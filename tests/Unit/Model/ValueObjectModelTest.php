<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Unit\Model;

use LogicException;
use MSSTC4PHP\ValueObject\Exception\InvalidValueObjectException;
use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use MSSTC4PHP\ValueObject\Payload\ValueObjectMapper;
use MSSTC4PHP\ValueObject\Primitive\IntRange;
use MSSTC4PHP\ValueObject\Yii2\Internal\CompositeJson;
use MSSTC4PHP\ValueObject\Yii2\Internal\DefaultMapper;
use MSSTC4PHP\ValueObject\Yii2\Internal\ModelValues;
use MSSTC4PHP\ValueObject\Yii2\Model\ValueObjectModel;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\AppCodecs;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Code;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\CodeForm;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\ConditionalForm;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\ContactForm;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\LongCode;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Priority;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\ShortCode;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Size;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\SizeCodec;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\SizeForm;
use MSSTC4PHP\ValueObject\Yii2\Tests\Support\YiiApplication;
use MSSTC4PHP\ValueObject\Yii2\Validator\ValueObjectValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use yii\BaseYii;

#[CoversTrait(ValueObjectModel::class)]
#[CoversClass(ModelValues::class)]
#[UsesClass(DefaultMapper::class)]
#[UsesClass(ValueObjectValidator::class)]
#[UsesClass(CompositeJson::class)]
final class ValueObjectModelTest extends TestCase
{
    use YiiApplication;

    public function testValidatedScalarBackedAttribute(): void
    {
        $form = $this->validForm(['email' => 'user@example.com']);

        $email = $form->valueObject('email');

        self::assertInstanceOf(EmailAddress::class, $email);
        self::assertSame('user@example.com', (string) $email);
    }

    public function testBackedEnumAttribute(): void
    {
        self::assertSame(Priority::High, $this->validForm(['priority' => '2'])->valueObject('priority'));
    }

    public function testCompositeFromJsonText(): void
    {
        $range = $this->validForm(['range' => '{"min":1,"max":5}'])->valueObject('range');

        self::assertEquals(new IntRange(1, 5), $range);
    }

    public function testCompositeFromArray(): void
    {
        self::assertEquals(new IntRange(2, 3), $this->validForm(['range' => ['min' => 2, 'max' => 3]])->valueObject('range'));
    }

    public function testCustomMapperOfTheValidatorIsUsed(): void
    {
        self::assertEquals(new Size(3, 4), $this->validForm(['size' => ['width' => 3, 'height' => 4]])->valueObject('size'));
    }

    public function testInstanceInTheAttributeIsReturnedAsIs(): void
    {
        $email = EmailAddress::from('user@example.com');

        self::assertSame($email, $this->validForm(['email' => $email])->valueObject('email'));
    }

    public function testEmptyValuesAreNull(): void
    {
        $form = $this->validForm(['email' => '', 'range' => null]);

        self::assertNull($form->valueObject('email'));
        self::assertNull($form->valueObject('range'));
    }

    public function testValueObjectsListsEveryValidatedAttribute(): void
    {
        $form = $this->validForm(['email' => 'user@example.com', 'name' => 'Ann']);

        $objects = $form->valueObjects();

        self::assertSame(['email', 'range', 'priority', 'size'], array_keys($objects));
        self::assertInstanceOf(EmailAddress::class, $objects['email']);
        self::assertNull($objects['range']);
    }

    public function testModelWithErrorsIsALogicException(): void
    {
        $form = new ContactForm();
        $form->load(['email' => 'nope'], '');
        self::assertFalse($form->validate());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('errors');
        $form->valueObject('email');
    }

    public function testErrorsOnAnotherAttributeBlockTheRead(): void
    {
        $form = new ContactForm();
        $form->load(['email' => 'user@example.com', 'range' => 'nope'], '');
        self::assertFalse($form->validate());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('validation errors');
        $form->valueObject('email');
    }

    public function testValueObjectsOnAModelWithErrorsIsALogicException(): void
    {
        $form = new ContactForm();
        $form->load(['email' => 'nope'], '');
        $form->validate();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has validation errors');
        $form->valueObjects();
    }

    public function testAttributeWithoutTheValidatorIsALogicException(): void
    {
        $form = $this->validForm(['name' => 'Ann']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('name');
        $form->valueObject('name');
    }

    public function testUnvalidatedInputIsALogicException(): void
    {
        $form = new ContactForm();
        $form->load(['email' => 'nope'], '');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('validate()');
        $form->valueObject('email');
    }

    public function testTheLogicExceptionCarriesNoPartOfTheValueWhileTheCauseQuotesIt(): void
    {
        $form = new ContactForm();
        $form->load(['email' => 'SeCrEt-7f3a'], '');

        try {
            $form->valueObject('email');
            self::fail('Expected LogicException.');
        } catch (LogicException $error) {
            self::assertSame('Attribute "email" of ' . ContactForm::class . ' does not hold a valid value: was validate() called?', $error->getMessage());
            self::assertInstanceOf(InvalidValueObjectException::class, $error->getPrevious());
            self::assertStringContainsString('SeCrEt-7f3a', $error->getPrevious()->getMessage());
        }
    }

    public function testTheErrorsLogicExceptionHasNoPrevious(): void
    {
        $form = new ContactForm();
        $form->load(['email' => 'nope'], '');
        self::assertFalse($form->validate());

        try {
            $form->valueObject('email');
            self::fail('Expected LogicException.');
        } catch (LogicException $error) {
            self::assertNull($error->getPrevious());
        }
    }

    public function testAnAbstractClassResolvingItsConcreteClassIsConverted(): void
    {
        foreach (['abc' => ShortCode::class, 'abcdef' => LongCode::class] as $value => $concrete) {
            $form = new CodeForm();
            $form->code = $value;
            self::assertTrue($form->validate());

            $code = $form->valueObject('code', Code::class);
            self::assertInstanceOf($concrete, $code);
            self::assertSame($value, (string) $code);
        }

        $form = new CodeForm();
        $form->code = 'abcdefgh';
        self::assertFalse($form->validate());

        $form = new CodeForm();
        $form->code = 'abcd';
        self::assertTrue($form->validate());
        self::assertEquals(['code' => new LongCode('abcd')], $form->valueObjects());
    }

    public function testAProvidesCodecsSetInTheContainerReachesTheModel(): void
    {
        BaseYii::$container->setSingleton(ValueObjectMapper::class, static fn (): ValueObjectMapper => new ValueObjectMapper(AppCodecs::all()));
        try {
            $form = new SizeForm();
            $form->size = '{"width":1,"height":2}';
            self::assertTrue($form->validate());

            self::assertEquals(new Size(1, 2), $form->valueObject('size'));
        } finally {
            BaseYii::$container->clear(ValueObjectMapper::class);
        }
    }

    public function testEmptyArrayPassesValidationAndIsNull(): void
    {
        $form = $this->validForm(['email' => []]);

        self::assertNull($form->valueObject('email'));
        self::assertNull($form->valueObjects()['email']);
    }

    public function testRuleSkippedByWhenIsNotConverted(): void
    {
        $form = new ConditionalForm();
        $form->checkEmail = false;
        $form->email = 'not an email';
        self::assertTrue($form->validate());

        self::assertNull($form->valueObject('email'));
    }

    public function testRuleWithWhenStillConvertsWhenItApplies(): void
    {
        $form = new ConditionalForm();
        $form->email = 'user@example.com';
        self::assertTrue($form->validate());

        self::assertInstanceOf(EmailAddress::class, $form->valueObject('email'));
    }

    public function testEmptyArrayIsAnErrorWhenSkipOnEmptyIsOff(): void
    {
        $form = new ConditionalForm();
        $form->strictEmail = [];

        self::assertFalse($form->validate());
    }

    public function testVirtualGetterSetterAttribute(): void
    {
        $form = new ConditionalForm();
        $form->setNickEmail('user@example.com');
        self::assertTrue($form->validate());

        self::assertInstanceOf(EmailAddress::class, $form->valueObject('nickEmail'));
    }

    public function testContainerMapperIsUsedByRuleAndConversion(): void
    {
        BaseYii::$container->setSingleton(ValueObjectMapper::class, static fn (): ValueObjectMapper => new ValueObjectMapper([new SizeCodec()]));
        try {
            $form = new SizeForm();
            $form->size = ['width' => 1, 'height' => 2];
            self::assertTrue($form->validate());

            self::assertEquals(new Size(1, 2), $form->valueObject('size'));
        } finally {
            BaseYii::$container->clear(ValueObjectMapper::class);
        }
    }

    public function testExpectedClassTypesTheResult(): void
    {
        $form = $this->validForm(['email' => 'user@example.com']);

        self::assertInstanceOf(EmailAddress::class, $form->valueObject('email', EmailAddress::class));
    }

    public function testExpectedClassMismatchIsALogicException(): void
    {
        $form = $this->validForm(['email' => 'user@example.com']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is validated as ' . EmailAddress::class . ', not ' . IntRange::class . '.');
        $form->valueObject('email', IntRange::class);
    }

    public function testRuleWithoutAClassIsALogicException(): void
    {
        $form = $this->validForm(['email' => 'user@example.com']);
        foreach ($form->getValidators() as $validator) {
            if ($validator instanceof ValueObjectValidator) {
                $validator->valueObjectClass = null;
            }
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no valueObjectClass');
        $form->valueObject('email');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validForm(array $data): ContactForm
    {
        $form = new ContactForm();
        $form->load($data, '');
        self::assertTrue($form->validate(), json_encode($form->getErrors(), \JSON_THROW_ON_ERROR));

        return $form;
    }
}
