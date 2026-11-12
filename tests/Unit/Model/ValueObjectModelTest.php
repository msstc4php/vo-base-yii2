<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Unit\Model;

use LogicException;
use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use MSSTC4PHP\ValueObject\Primitive\IntRange;
use MSSTC4PHP\ValueObject\Yii2\Internal\CompositeJson;
use MSSTC4PHP\ValueObject\Yii2\Model\ValueObjectModel;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\ContactForm;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Priority;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Size;
use MSSTC4PHP\ValueObject\Yii2\Tests\Support\YiiApplication;
use MSSTC4PHP\ValueObject\Yii2\Validator\ValueObjectValidator;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversTrait(ValueObjectModel::class)]
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

    public function testValueObjectsOnAModelWithErrorsIsALogicException(): void
    {
        $form = new ContactForm();
        $form->load(['email' => 'nope'], '');
        $form->validate();

        $this->expectException(LogicException::class);
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
