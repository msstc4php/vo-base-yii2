<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Unit\Behavior;

use Closure;
use DateTimeImmutable;
use JsonException;
use MSSTC4PHP\ValueObject\Exception\InvalidEmailAddressException;
use MSSTC4PHP\ValueObject\Exception\InvalidIntRangeException;
use MSSTC4PHP\ValueObject\Exception\InvalidPayloadException;
use MSSTC4PHP\ValueObject\Exception\InvalidPositiveIntException;
use MSSTC4PHP\ValueObject\Exception\InvalidValueObjectException;
use MSSTC4PHP\ValueObject\Format\HexColor;
use MSSTC4PHP\ValueObject\I18n\CountryCode;
use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use MSSTC4PHP\ValueObject\Payload\ValueObjectMapper;
use MSSTC4PHP\ValueObject\Primitive\BoundedString;
use MSSTC4PHP\ValueObject\Primitive\IntRange;
use MSSTC4PHP\ValueObject\Primitive\Percentage;
use MSSTC4PHP\ValueObject\Primitive\PositiveInt;
use MSSTC4PHP\ValueObject\Temporal\DateRange;
use MSSTC4PHP\ValueObject\Yii2\Behavior\ValueObjectAttributeBehavior;
use MSSTC4PHP\ValueObject\Yii2\Behavior\ValueObjectAttributes;
use MSSTC4PHP\ValueObject\Yii2\Internal\CompositeJson;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\PlainProfile;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Priority;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Profile;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Size;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\SizeCodec;
use MSSTC4PHP\ValueObject\Yii2\Tests\Support\YiiApplication;
use MSSTC4PHP\ValueObject\Yii2\Validator\ValueObjectValidator;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;
use yii\base\Behavior;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\base\ModelEvent;
use yii\db\AfterSaveEvent;
use yii\db\BaseActiveRecord;
use yii\db\IntegrityException;

#[CoversClass(ValueObjectAttributeBehavior::class)]
#[CoversTrait(ValueObjectAttributes::class)]
#[UsesClass(ValueObjectValidator::class)]
#[CoversClass(CompositeJson::class)]
final class ValueObjectAttributeBehaviorTest extends TestCase
{
    use YiiApplication;

    private const array STORED = [
        'id' => 1,
        'name' => 'Ann',
        'email' => 'ann@example.com',
        'age' => 42,
        'share' => 12.5,
        'country' => 'DE',
        'priority' => 2,
        'color' => '#ffaa00',
        'range' => '{"min":1,"max":5}',
        'label' => '{"value":"hi","minLength":1,"maxLength":10}',
        'period' => '{"start":"2026-11-01T00:00:00.000000+00:00","end":"2026-11-30T23:59:59.500000+00:00"}',
    ];

    #[Override]
    protected function setUp(): void
    {
        Profile::createTable($this->db());
    }

    public function testInsertStoresPayloadsAndKeepsValueObjects(): void
    {
        $profile = $this->newProfile();

        self::assertTrue($profile->save());

        self::assertSame(self::STORED, $this->storedRow());
        self::assertInstanceOf(EmailAddress::class, $profile->email);
        self::assertTrue(new IntRange(1, 5)->equals($this->valueObject($profile->range)));
        self::assertSame([], $profile->getDirtyAttributes());
        self::assertSame($profile->email, $profile->getOldAttribute('email'));
    }

    public function testFindHydratesEveryKind(): void
    {
        $this->newProfile()->save();

        $profile = $this->findProfile();

        self::assertEquals(EmailAddress::from('ann@example.com'), $profile->email);
        self::assertEquals(PositiveInt::from(42), $profile->age);
        self::assertEquals(Percentage::from(12.5), $profile->share);
        self::assertSame(CountryCode::DE, $profile->country);
        self::assertSame(Priority::High, $profile->priority);
        self::assertEquals(HexColor::from('#ffaa00'), $profile->color);
        self::assertEquals(new IntRange(1, 5), $profile->range);
        self::assertEquals(new BoundedString('hi', 1, 10), $profile->label);
        self::assertInstanceOf(DateRange::class, $profile->period);
        self::assertSame('2026-11-30T23:59:59.500000+00:00', $profile->period->end->format('Y-m-d\\TH:i:s.uP'));
        self::assertSame([], $profile->getDirtyAttributes());
        self::assertSame($profile->email, $profile->getOldAttribute('email'));
    }

    public function testNullStaysNull(): void
    {
        $profile = new Profile(['name' => 'Ann']);
        self::assertTrue($profile->save());
        self::assertNull($profile->email);

        $found = $this->findProfile();
        foreach (array_keys(Profile::MAP) as $attribute) {
            self::assertNull($found->getAttribute($attribute), $attribute);
        }

        self::assertSame(0, $found->update());
    }

    public function testAnUnchangedRecordIssuesNoUpdate(): void
    {
        $this->newProfile()->save();
        $profile = $this->findProfile();

        self::assertSame(0, $profile->update());
        self::assertInstanceOf(EmailAddress::class, $profile->email);
    }

    public function testAnEqualButNewValueObjectIsNotWritten(): void
    {
        $this->newProfile()->save();
        $profile = $this->findProfile();
        $profile->email = EmailAddress::from('ann@example.com');
        $profile->range = new IntRange(1, 5);

        self::assertSame(0, $profile->update());
    }

    public function testAChangedValueObjectIsWritten(): void
    {
        $this->newProfile()->save();
        $profile = $this->findProfile();
        $profile->email = EmailAddress::from('bob@example.com');
        $profile->range = new IntRange(2, 3);

        self::assertSame(['email', 'range'], array_keys($profile->getDirtyAttributes()));
        self::assertSame(1, $profile->update());
        self::assertSame([], $profile->getDirtyAttributes());
        self::assertEquals(EmailAddress::from('bob@example.com'), $profile->email);
        self::assertSame($profile->range, $profile->getOldAttribute('range'));

        $row = $this->storedRow();
        self::assertSame('bob@example.com', $row['email']);
        self::assertSame('{"min":2,"max":3}', $row['range']);
        self::assertEquals(PositiveInt::from(42), $profile->getOldAttribute('age'));
    }

    public function testAPartialSaveRestoresTheUnsavedAttributesToo(): void
    {
        $this->newProfile()->save();
        $profile = $this->findProfile();
        $profile->email = EmailAddress::from('bob@example.com');
        $profile->age = PositiveInt::from(7);

        self::assertSame(1, $profile->update(true, ['email']));

        self::assertEquals(PositiveInt::from(7), $profile->age);
        self::assertEquals(PositiveInt::from(42), $profile->getOldAttribute('age'));
        self::assertSame(['age'], array_keys($profile->getDirtyAttributes()));
        self::assertSame(42, $this->storedRow()['age']);
    }

    public function testSettingNullClearsTheColumn(): void
    {
        $this->newProfile()->save();
        $profile = $this->findProfile();
        $profile->email = null;

        self::assertSame(1, $profile->update());
        self::assertNull($this->storedRow()['email']);
        self::assertNull($profile->email);
    }

    public function testRawInputIsValidatedAndCanonicalisedOnSave(): void
    {
        $profile = new Profile(['email' => 'ann@example.com', 'age' => '42', 'color' => '#FFAA00', 'country' => 'DE', 'range' => ['min' => 1, 'max' => 5]]);

        self::assertTrue($profile->save());

        $row = $this->storedRow();
        self::assertSame('#ffaa00', $row['color']);
        self::assertSame(42, $row['age']);
        self::assertSame('{"min":1,"max":5}', $row['range']);
        self::assertEquals(HexColor::from('#ffaa00'), $profile->color);
        self::assertSame(CountryCode::DE, $profile->country);
    }

    public function testRawCompositeJsonInputIsSaved(): void
    {
        $profile = new Profile(['range' => ' { "max": 5, "min": 1 } ']);

        self::assertTrue($profile->save(false));
        self::assertSame('{"min":1,"max":5}', $this->storedRow()['range']);
        self::assertEquals(new IntRange(1, 5), $profile->range);
    }

    public function testRawCompositeInvalidJsonFailsTheSave(): void
    {
        $profile = new Profile(['range' => '{"min":1']);

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('payload is not valid JSON');

        $profile->save(false);
    }

    public function testInvalidRawInputFailsTheSave(): void
    {
        $profile = new Profile(['email' => 'not-an-email']);

        $this->expectException(InvalidEmailAddressException::class);

        $profile->save(false);
    }

    public function testAWrongTypeFailsTheSave(): void
    {
        $profile = new Profile(['email' => new stdClass()]);

        $this->expectException(InvalidPayloadException::class);

        $profile->save(false);
    }

    public function testAnotherValueObjectFailsTheSave(): void
    {
        $profile = new Profile(['email' => PositiveInt::from(1)]);

        $this->expectException(InvalidPayloadException::class);

        $profile->save(false);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function rawBoundedStrings(): iterable
    {
        yield 'payload array' => [['value' => str_repeat('x', 500), 'minLength' => 0, 'maxLength' => null]];
        yield 'payload JSON' => ['{"value":"hi","minLength":0,"maxLength":null}'];
        yield 'bare string' => ['hi'];
    }

    #[DataProvider('rawBoundedStrings')]
    public function testABoundedStringIsNeverBuiltFromRawInput(mixed $raw): void
    {
        $profile = new Profile();
        $profile->setAttributes(['label' => $raw], false);

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('The "label" attribute takes a ' . BoundedString::class . ' instance, ');

        $profile->save(false);
    }

    public function testANullBoundedStringIsStored(): void
    {
        $profile = new Profile(['label' => null]);

        self::assertTrue($profile->save(false));
        self::assertNull($this->storedRow()['label']);
    }

    public function testAVetoedInsertKeepsTheValueObjects(): void
    {
        $profile = $this->newProfile();
        $email = $profile->email;
        $profile->on(BaseActiveRecord::EVENT_BEFORE_INSERT, static function (ModelEvent $event): void {
            $event->isValid = false;
        });

        self::assertFalse($profile->save());

        self::assertSame($email, $profile->email);
        self::assertTrue($profile->getIsNewRecord());
        self::assertFalse($profile->save(false));
        self::assertSame($email, $profile->email);
    }

    public function testAVetoedUpdateKeepsTheValueObjectsAndTheDirtyState(): void
    {
        $this->newProfile()->save();
        $profile = $this->findProfile();
        $profile->email = EmailAddress::from('bob@example.com');

        $age = $profile->age;
        $profile->on(BaseActiveRecord::EVENT_BEFORE_UPDATE, static function (ModelEvent $event): void {
            $event->isValid = false;
        });

        self::assertFalse($profile->update());

        self::assertEquals(EmailAddress::from('bob@example.com'), $profile->email);
        self::assertEquals(EmailAddress::from('ann@example.com'), $profile->getOldAttribute('email'));
        self::assertSame($age, $profile->age);
        self::assertSame($age, $profile->getOldAttribute('age'));
        self::assertSame(['email'], array_keys($profile->getDirtyAttributes()));
    }

    public function testInsertAndUpdateValidateByDefault(): void
    {
        self::assertFalse(new Profile(['email' => 'nope'])->insert());

        $this->newProfile()->save();
        $profile = $this->findProfile();
        $profile->email = 'nope';

        self::assertFalse($profile->update());
        self::assertSame(['Email is invalid.'], $profile->getErrors('email'));
    }

    public function testAVetoedUpdateRestoresEveryAttributeAndKeepsEqualOnesClean(): void
    {
        $this->insertRow(['age' => 42, 'range' => '{"min":1,"max":5}']);
        $profile = $this->findProfile();
        $profile->range = new IntRange(1, 5);
        $profile->on(BaseActiveRecord::EVENT_BEFORE_UPDATE, static function (ModelEvent $event): void {
            $event->isValid = false;
        });

        self::assertFalse($profile->update());

        self::assertEquals(PositiveInt::from(42), $profile->getOldAttribute('age'));
        self::assertSame($profile->range, $profile->getOldAttribute('range'));
        self::assertSame([], $profile->getDirtyAttributes());
    }

    public function testAFailedInsertKeepsTheValueObjects(): void
    {
        $this->newProfile()->save();
        $duplicate = $this->newProfile();
        $email = $duplicate->email;

        self::assertInstanceOf(IntegrityException::class, $this->failure(static fn (): bool => $duplicate->save()));

        self::assertSame($email, $duplicate->email);
        self::assertInstanceOf(IntRange::class, $duplicate->range);
    }

    public function testWithoutTheTraitHydrateRestoresAfterAVeto(): void
    {
        $profile = new PlainProfile(['email' => EmailAddress::from('ann@example.com')]);
        $profile->on(BaseActiveRecord::EVENT_BEFORE_INSERT, static function (ModelEvent $event): void {
            $event->isValid = false;
        });

        self::assertFalse($profile->save());
        self::assertSame('ann@example.com', $profile->email);

        $behavior = $profile->getBehavior('valueObjects');
        self::assertInstanceOf(ValueObjectAttributeBehavior::class, $behavior);
        $behavior->hydrate();
        $behavior->hydrate();

        self::assertEquals(EmailAddress::from('ann@example.com'), $profile->email);
    }

    public function testASecondSaveAfterAVetoWithoutTheTraitStillWorks(): void
    {
        $profile = new PlainProfile(['email' => EmailAddress::from('ann@example.com'), 'label' => new BoundedString('hi', 1, 10)]);
        $veto = static function (ModelEvent $event): void {
            $event->isValid = false;
        };
        $profile->on(BaseActiveRecord::EVENT_BEFORE_INSERT, $veto);
        self::assertFalse($profile->save());
        $profile->off(BaseActiveRecord::EVENT_BEFORE_INSERT, $veto);

        self::assertTrue($profile->save());
        self::assertSame('{"value":"hi","minLength":1,"maxLength":10}', $this->storedRow()['label']);
        self::assertInstanceOf(BoundedString::class, $profile->label);
    }

    public function testRejectedRawInputIsNeverTurnedIntoAValueObject(): void
    {
        $profile = new Profile(['email' => EmailAddress::from('ann@example.com')]);
        $profile->setAttributes(['label' => ['value' => 'x', 'minLength' => 0, 'maxLength' => null]], false);

        self::assertInstanceOf(InvalidPayloadException::class, $this->failure(static fn (): bool => $profile->save(false)));
        self::assertIsArray($profile->label);
        self::assertInstanceOf(EmailAddress::class, $profile->email);
    }

    public function testAfterSaveHandlersSeeValueObjectsAndPayloadsInChangedAttributes(): void
    {
        $this->newProfile()->save();
        $profile = $this->findProfile();
        $profile->email = EmailAddress::from('bob@example.com');

        $seen = [];
        $profile->on(BaseActiveRecord::EVENT_AFTER_UPDATE, static function (AfterSaveEvent $event) use (&$seen): void {
            \assert($event->sender instanceof Profile);
            $seen = [$event->sender->email, $event->changedAttributes];
        });

        $profile->update();

        self::assertEquals([EmailAddress::from('bob@example.com'), ['email' => 'ann@example.com']], $seen);
    }

    public function testAnUnencodablePayloadFailsTheSave(): void
    {
        $profile = new Profile(['label' => new BoundedString("\xB1\x31")]);

        $e = $this->failure(static fn (): bool => $profile->save(false));

        self::assertInstanceOf(InvalidPayloadException::class, $e);
        self::assertInstanceOf(JsonException::class, $e->getPrevious());
        self::assertStringStartsWith('A ' . BoundedString::class . ' payload cannot be encoded as JSON: ', $e->getMessage());

        self::assertInstanceOf(BoundedString::class, $profile->label);
    }

    public function testCompositeJsonThatIsNotAnObjectFailsLoudly(): void
    {
        $this->insertRow(['range' => '5']);

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('payload must be a JSON object, int given.');

        $this->findProfile();
    }

    public function testATypoInTheMapFailsAtFirstUseNotAtAttach(): void
    {
        $profile = new Profile(['email' => EmailAddress::from('ann@example.com')]);
        $profile->detachBehavior('valueObjects');
        $profile->attachBehavior('typo', ['class' => ValueObjectAttributeBehavior::class, 'attributes' => ['emial' => EmailAddress::class]]);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('maps "emial", which is not an attribute of ' . Profile::class . '.');

        $profile->save(false);
    }

    public function testATypoInTheMapFailsOnRefreshToo(): void
    {
        $this->newProfile()->save();
        $profile = $this->findProfile();
        $profile->attachBehavior('typo', ['class' => ValueObjectAttributeBehavior::class, 'attributes' => ['emial' => EmailAddress::class]]);

        $this->expectException(InvalidConfigException::class);

        $profile->refresh();
    }

    public function testTheTraitIgnoresOtherBehaviors(): void
    {
        $profile = $this->newProfile();
        $profile->attachBehavior('other', ['class' => Behavior::class]);

        self::assertTrue($profile->save());
        self::assertSame(1, $profile->updateAttributes(['email' => EmailAddress::from('bob@example.com')]));
        self::assertInstanceOf(EmailAddress::class, $profile->email);
    }

    public function testAModelBuildsBeforeItsTableExists(): void
    {
        $this->db()->createCommand()->dropTable('profile')->execute();

        $profile = new PlainProfile();

        self::assertInstanceOf(ValueObjectAttributeBehavior::class, $profile->getBehavior('valueObjects'));
    }

    public function testUpdateAttributesWritesPayloadsAndKeepsValueObjects(): void
    {
        $this->newProfile()->save();
        $profile = $this->findProfile();
        $age = $profile->age;

        self::assertSame(1, $profile->updateAttributes(['email' => EmailAddress::from('bob@example.com'), 'range' => new IntRange(2, 3), 'name']));

        $row = $this->storedRow();
        self::assertSame('bob@example.com', $row['email']);
        self::assertSame('{"min":2,"max":3}', $row['range']);
        self::assertEquals(EmailAddress::from('bob@example.com'), $profile->email);
        self::assertSame($profile->email, $profile->getOldAttribute('email'));
        self::assertSame($age, $profile->age);
        self::assertSame([], $profile->getDirtyAttributes());
    }

    public function testUpdateAttributesOfAnUnchangedValueObjectWritesNothing(): void
    {
        $this->newProfile()->save();
        $profile = $this->findProfile();

        self::assertSame(0, $profile->updateAttributes(['email' => EmailAddress::from('ann@example.com')]));
        self::assertInstanceOf(EmailAddress::class, $profile->email);
    }

    public function testUpdateAttributesRestoresOnFailure(): void
    {
        $this->newProfile()->save();
        $other = $this->newProfile();
        $other->email = EmailAddress::from('bob@example.com');
        $other->save();

        $profile = $this->findProfile();
        $email = EmailAddress::from('bob@example.com');

        self::assertInstanceOf(IntegrityException::class, $this->failure(static fn (): int => $profile->updateAttributes(['email' => $email])));
        self::assertSame($email, $profile->email);
        self::assertInstanceOf(IntRange::class, $profile->range);
    }

    public function testAPartialSelectLeavesTheOtherColumnsAlone(): void
    {
        $this->newProfile()->save();
        $profile = Profile::find()->select(['id', 'name'])->one();
        self::assertInstanceOf(Profile::class, $profile);
        $profile->name = 'Bea';

        self::assertSame(['name'], array_keys($profile->getDirtyAttributes()));
        self::assertSame(1, $profile->update());
        self::assertSame(array_replace(self::STORED, ['name' => 'Bea']), $this->storedRow());
    }

    public function testRefreshHydrates(): void
    {
        $profile = $this->newProfile();
        $profile->save();
        $this->db()->createCommand()->update('profile', ['email' => 'bob@example.com'])->execute();

        self::assertTrue($profile->refresh());

        self::assertEquals(EmailAddress::from('bob@example.com'), $profile->email);
        self::assertSame($profile->email, $profile->getOldAttribute('email'));
    }

    public function testCompositeJsonWrittenElsewhereReadsBack(): void
    {
        $this->insertRow(['range' => ' { "max": 5, "min": 1 } ']);

        self::assertEquals(new IntRange(1, 5), $this->findProfile()->range);
    }

    /**
     * @return iterable<string, array{array<string, scalar>, class-string<Throwable>}>
     */
    public static function invalidStoredValues(): iterable
    {
        yield 'invalid string' => [['email' => 'not-an-email'], InvalidEmailAddressException::class];
        yield 'invalid int' => [['age' => -5], InvalidPositiveIntException::class];
        yield 'unknown enum case' => [['country' => 'XX'], InvalidPayloadException::class];
        yield 'composite not JSON' => [['range' => 'garbage'], InvalidPayloadException::class];
        yield 'composite broken JSON' => [['range' => '{"min":1'], InvalidPayloadException::class];
        yield 'composite wrong shape' => [['range' => '{"from":1}'], InvalidPayloadException::class];
        yield 'composite invalid content' => [['range' => '{"min":5,"max":1}'], InvalidIntRangeException::class];
    }

    /**
     * @param array<string, scalar> $columns
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('invalidStoredValues')]
    public function testAnInvalidStoredValueFailsLoudly(array $columns, string $exception): void
    {
        $this->insertRow($columns);

        $this->expectException($exception);

        $this->findProfile();
    }

    public function testBrokenJsonKeepsTheParserError(): void
    {
        $this->insertRow(['range' => '{"min":1']);

        try {
            $this->findProfile();
            self::fail('No exception.');
        } catch (InvalidValueObjectException $e) {
            self::assertInstanceOf(JsonException::class, $e->getPrevious());
            self::assertStringStartsWith('A ' . IntRange::class . ' payload is not valid JSON: ', $e->getMessage());
        }
    }

    public function testAJsonLookingStringForAScalarIsNotDecoded(): void
    {
        $this->insertRow(['email' => '{"min":1}']);

        $this->expectException(InvalidEmailAddressException::class);

        $this->findProfile();
    }

    public function testAnArrayColumnGetsTheArrayPayload(): void
    {
        $profile = $this->newProfile();
        $column = Profile::getTableSchema()->getColumn('range');
        self::assertNotNull($column);
        $column->phpType = 'array';
        $behavior = $profile->getBehavior('valueObjects');
        self::assertInstanceOf(ValueObjectAttributeBehavior::class, $behavior);

        $behavior->dehydrate();
        self::assertSame(['min' => 1, 'max' => 5], $profile->range);
        self::assertSame('ann@example.com', $profile->email);

        $behavior->hydrate();
        self::assertEquals(new IntRange(1, 5), $profile->range);
    }

    public function testACustomMapperMapsACustomComposite(): void
    {
        $profile = new Profile();
        $profile->detachBehavior('valueObjects');
        $profile->attachBehavior('size', [
            'class' => ValueObjectAttributeBehavior::class,
            'attributes' => ['range' => Size::class],
            'mapper' => new ValueObjectMapper([new SizeCodec()]),
        ]);
        $profile->setAttribute('range', new Size(2, 3));

        self::assertTrue($profile->save());
        self::assertSame('{"width":2,"height":3}', $this->storedRow()['range']);
        self::assertEquals(new Size(2, 3), $profile->range);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function invalidMaps(): iterable
    {
        yield 'not a value object' => [['email' => DateTimeImmutable::class], '"email" => DateTimeImmutable is not.'];
        yield 'composite without codec' => [['range' => Size::class], '"range" => ' . Size::class . ' is not.'];
        yield 'no attribute name' => [[EmailAddress::class], '"0" => ' . EmailAddress::class . ' is not.'];
        yield 'not a class name' => [['email' => 42], '"email" => int is not.'];
    }

    /**
     * @param array<array-key, mixed> $map
     */
    #[DataProvider('invalidMaps')]
    public function testAnUnsupportedMapFailsAtAttach(array $map, string $message): void
    {
        $profile = new Profile();
        $profile->detachBehavior('valueObjects');

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage($message);

        $profile->attachBehavior('broken', ['class' => ValueObjectAttributeBehavior::class, 'attributes' => $map]);
    }

    public function testOnlyAnActiveRecordCanOwnIt(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('attaches to a yii\db\BaseActiveRecord, yii\base\Component given.');

        new Component()->attachBehavior('vo', ['class' => ValueObjectAttributeBehavior::class, 'attributes' => ['email' => EmailAddress::class]]);
    }

    /**
     * @param Closure(): mixed $action
     */
    private function failure(Closure $action): Throwable
    {
        try {
            $action();
        } catch (Throwable $e) {
            return $e;
        }

        self::fail('No exception.');
    }

    private function newProfile(): Profile
    {
        return new Profile([
            'name' => 'Ann',
            'email' => EmailAddress::from('ann@example.com'),
            'age' => PositiveInt::from(42),
            'share' => Percentage::from(12.5),
            'country' => CountryCode::DE,
            'priority' => Priority::High,
            'color' => HexColor::from('#FFAA00'),
            'range' => new IntRange(1, 5),
            'label' => new BoundedString('hi', 1, 10),
            'period' => new DateRange(new DateTimeImmutable('2026-11-01T00:00:00Z'), new DateTimeImmutable('2026-11-30T23:59:59.5Z')),
        ]);
    }

    private function findProfile(): Profile
    {
        $profile = Profile::findOne(1);
        self::assertInstanceOf(Profile::class, $profile);

        return $profile;
    }

    /**
     * @param array<string, scalar> $columns
     */
    private function insertRow(array $columns): void
    {
        $this->db()->createCommand()->insert('profile', ['id' => 1, 'name' => 'Ann'] + $columns)->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private function storedRow(): array
    {
        $row = $this->db()->createCommand('SELECT * FROM profile WHERE id = 1')->queryOne();
        self::assertIsArray($row);

        $typed = [];
        foreach ($row as $name => $value) {
            $typed[(string) $name] = Profile::getTableSchema()->getColumn((string) $name)?->phpTypecast($value);
        }

        return $typed;
    }

    private function valueObject(mixed $value): IntRange
    {
        self::assertInstanceOf(IntRange::class, $value);

        return $value;
    }
}
