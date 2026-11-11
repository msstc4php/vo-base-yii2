<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Unit\Behavior;

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
use MSSTC4PHP\ValueObject\Yii2\Behavior\ValueObjectAttributeBehavior;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Priority;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Profile;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Size;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\SizeCodec;
use MSSTC4PHP\ValueObject\Yii2\Tests\Support\YiiApplication;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

#[CoversClass(ValueObjectAttributeBehavior::class)]
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
