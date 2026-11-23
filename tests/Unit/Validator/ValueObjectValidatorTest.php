<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Unit\Validator;

use BackedEnum;
use DateTimeImmutable;
use MSSTC4PHP\ValueObject\Contract\ValueObject;
use MSSTC4PHP\ValueObject\I18n\CountryCode;
use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use MSSTC4PHP\ValueObject\Payload\ValueObjectMapper;
use MSSTC4PHP\ValueObject\Primitive\BoundedString;
use MSSTC4PHP\ValueObject\Primitive\IntRange;
use MSSTC4PHP\ValueObject\Primitive\Percentage;
use MSSTC4PHP\ValueObject\Primitive\PositiveInt;
use MSSTC4PHP\ValueObject\Yii2\Internal\CompositeJson;
use MSSTC4PHP\ValueObject\Yii2\Internal\DefaultMapper;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Priority;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Size;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\SizeCodec;
use MSSTC4PHP\ValueObject\Yii2\Tests\Support\YiiApplication;
use MSSTC4PHP\ValueObject\Yii2\Validator\ValueObjectValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use yii\base\DynamicModel;
use yii\base\InvalidConfigException;
use yii\web\View;

#[UsesClass(DefaultMapper::class)]
#[CoversClass(ValueObjectValidator::class)]
#[CoversClass(CompositeJson::class)]
final class ValueObjectValidatorTest extends TestCase
{
    use YiiApplication;

    /**
     * @return iterable<string, array{class-string<ValueObject|BackedEnum>, mixed}>
     */
    public static function validInput(): iterable
    {
        yield 'string-backed' => [EmailAddress::class, 'user@example.com'];
        yield 'int-backed, form string' => [PositiveInt::class, '42'];
        yield 'int-backed, int' => [PositiveInt::class, 42];
        yield 'float-backed, form string' => [Percentage::class, '12.5'];
        yield 'float-backed, int' => [Percentage::class, 12];
        yield 'string enum' => [CountryCode::class, 'DE'];
        yield 'int enum, form string' => [Priority::class, '2'];
        yield 'int enum, int' => [Priority::class, 1];
        yield 'composite array' => [IntRange::class, ['min' => 1, 'max' => 5]];
        yield 'composite JSON text, as the behavior takes it' => [IntRange::class, '{"min":1,"max":5}'];
        yield 'already the value object' => [EmailAddress::class, EmailAddress::from('user@example.com')];
        yield 'already the enum' => [CountryCode::class, CountryCode::DE];
    }

    /**
     * @param class-string<ValueObject|BackedEnum> $class
     */
    #[DataProvider('validInput')]
    public function testAcceptsWhatTheValueObjectAccepts(string $class, mixed $value): void
    {
        $model = $this->validate($class, $value);

        self::assertFalse($model->hasErrors(), implode(' ', $model->getErrorSummary(true)));
    }

    /**
     * @return iterable<string, array{class-string<ValueObject|BackedEnum>, mixed}>
     */
    public static function invalidInput(): iterable
    {
        yield 'invalid string' => [EmailAddress::class, 'not-an-email'];
        yield 'int out of range' => [PositiveInt::class, '0'];
        yield 'not a number' => [PositiveInt::class, 'abc'];
        yield 'fraction for an int' => [PositiveInt::class, '1.5'];
        yield 'non-canonical int' => [PositiveInt::class, '080'];
        yield 'float out of range' => [Percentage::class, '150'];
        yield 'unknown enum case' => [CountryCode::class, 'XX'];
        yield 'unknown int enum case' => [Priority::class, '9'];
        yield 'invalid composite' => [IntRange::class, ['min' => 5, 'max' => 1]];
        yield 'composite broken JSON' => [IntRange::class, '{"min":1'];
        yield 'composite JSON not an object' => [IntRange::class, '5'];
        yield 'array for a string' => [EmailAddress::class, ['user@example.com']];
        yield 'bool' => [PositiveInt::class, true];
        yield 'another object' => [EmailAddress::class, new stdClass()];
        yield 'another value object' => [EmailAddress::class, PositiveInt::from(1)];
    }

    /**
     * @param class-string<ValueObject|BackedEnum> $class
     */
    #[DataProvider('invalidInput')]
    public function testRejectsWhatTheValueObjectRejects(string $class, mixed $value): void
    {
        $model = $this->validate($class, $value);

        self::assertSame(['Field is invalid.'], $model->getErrors('field'));
    }

    public function testEmptyValuesAreLeftToRequired(): void
    {
        foreach ([null, ''] as $empty) {
            self::assertFalse($this->validate(EmailAddress::class, $empty, ['skipOnEmpty' => false])->hasErrors());
        }

        $model = new DynamicModel(['field' => '']);
        $model->addRule('field', 'required')->addRule('field', ValueObjectValidator::class, ['valueObjectClass' => EmailAddress::class]);

        self::assertFalse($model->validate());
        self::assertSame(['Field cannot be blank.'], $model->getErrors('field'));
    }

    public function testCustomMessageGetsTheAttributeAndTheValue(): void
    {
        $model = $this->validate(EmailAddress::class, 'nope', ['message' => '{attribute}: "{value}" is no e-mail.']);

        self::assertSame(['Field: "nope" is no e-mail.'], $model->getErrors('field'));
    }

    public function testWorksInModelRules(): void
    {
        $model = DynamicModel::validateData(
            ['email' => 'nope'],
            [['email', ValueObjectValidator::class, 'valueObjectClass' => EmailAddress::class]],
        );

        self::assertSame(['Email is invalid.'], $model->getErrors('email'));
    }

    public function testStandaloneValidation(): void
    {
        $validator = new ValueObjectValidator(['valueObjectClass' => EmailAddress::class, 'attributes' => 'field']);

        self::assertSame(['field'], $validator->attributes);
        self::assertTrue($validator->validate('user@example.com'));
        self::assertFalse($validator->validate('nope', $error));
        self::assertSame('the input value is invalid.', $error);
    }

    public function testACustomMapperValidatesACustomComposite(): void
    {
        $mapper = new ValueObjectMapper([new SizeCodec()]);

        self::assertFalse($this->validate(Size::class, ['width' => 2, 'height' => 3], ['mapper' => $mapper])->hasErrors());
        self::assertTrue($this->validate(Size::class, ['width' => '2'], ['mapper' => $mapper])->hasErrors());
    }

    public function testNoClientSideValidation(): void
    {
        $validator = new ValueObjectValidator(['valueObjectClass' => EmailAddress::class]);

        self::assertNull($validator->clientValidateAttribute(new DynamicModel(['field' => '']), 'field', new View()));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfiguration(): iterable
    {
        yield 'missing' => [[], '$valueObjectClass must name a value object class the payload mapper supports, null given.'];
        yield 'not a value object' => [['valueObjectClass' => DateTimeImmutable::class], 'DateTimeImmutable given.'];
        yield 'composite without codec' => [['valueObjectClass' => Size::class], Size::class . ' given.'];
        yield 'BoundedString' => [['valueObjectClass' => BoundedString::class], 'its payload carries its own constraints. For BoundedString, validate the length with Yii\'s "string" validator or use NonEmptyString.'];
        yield 'BoundedString with a custom mapper' => [['valueObjectClass' => BoundedString::class, 'mapper' => new ValueObjectMapper()], 'cannot validate ' . BoundedString::class . ' from user input'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfiguration')]
    public function testMisconfigurationFailsAtConstruction(array $config, string $message): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage($message);

        new ValueObjectValidator($config);
    }

    public function testTheClassKeyOfARuleNamesTheValidatorNotTheValueObject(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('null given.');

        DynamicModel::validateData(['email' => 'x'], [['email', ValueObjectValidator::class, 'class' => EmailAddress::class]]);
    }

    /**
     * @param class-string<ValueObject|BackedEnum> $class
     * @param array<string, mixed> $options
     */
    private function validate(string $class, mixed $value, array $options = []): DynamicModel
    {
        $model = new DynamicModel();
        $model->defineAttribute('field', $value);
        $model->addRule('field', ValueObjectValidator::class, ['valueObjectClass' => $class, ...$options]);
        $model->validate();

        return $model;
    }
}
