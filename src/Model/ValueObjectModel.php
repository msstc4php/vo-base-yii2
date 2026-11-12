<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Model;

use BackedEnum;
use LogicException;
use MSSTC4PHP\ValueObject\Contract\ValueObject;
use MSSTC4PHP\ValueObject\Exception\InvalidValueObjectException;
use MSSTC4PHP\ValueObject\Payload\ValueObjectMapper;
use MSSTC4PHP\ValueObject\Yii2\Internal\CompositeJson;
use MSSTC4PHP\ValueObject\Yii2\Validator\ValueObjectValidator;
use yii\base\Model;
use yii\BaseYii;

/**
 * Reads the validated attributes of a form or REST model as value objects. The class
 * and the mapper come from the attribute's `ValueObjectValidator` rule, so rule and
 * conversion cannot disagree. Call it after `validate()`: a model with errors, an
 * attribute without the rule, or a value that does not convert is a LogicException.
 *
 * @phpstan-require-extends Model
 */
trait ValueObjectModel
{
    /**
     * @return ValueObject|BackedEnum|null null for a null or empty attribute
     *
     * @throws LogicException when the model has errors, the attribute has no ValueObjectValidator rule, or its value does not convert
     */
    public function valueObject(string $attribute): ?object
    {
        $this->assertWithoutErrors();
        $validator = $this->valueObjectValidatorOf($attribute);

        return $validator instanceof ValueObjectValidator
            ? $this->convertAttribute($attribute, $validator)
            : throw new LogicException(\sprintf('Attribute "%s" has no %s rule in %s::rules().', $attribute, ValueObjectValidator::class, static::class));
    }

    /**
     * @return array<string, ValueObject|BackedEnum|null> every attribute that has a ValueObjectValidator rule
     *
     * @throws LogicException when the model has errors or a value does not convert
     */
    public function valueObjects(): array
    {
        $this->assertWithoutErrors();

        $objects = [];
        foreach ($this->getActiveValidators() as $validator) {
            if (!$validator instanceof ValueObjectValidator) {
                continue;
            }

            foreach ($validator->getAttributeNames() as $attribute) {
                if (!\is_string($attribute)) {
                    continue;
                }

                $objects[$attribute] = $this->convertAttribute($attribute, $validator);
            }
        }

        return $objects;
    }

    private function assertWithoutErrors(): void
    {
        if ($this->hasErrors()) {
            throw new LogicException(\sprintf('%s has validation errors: read value objects only after validate() succeeded.', static::class));
        }
    }

    private function valueObjectValidatorOf(string $attribute): ?ValueObjectValidator
    {
        foreach ($this->getActiveValidators($attribute) as $validator) {
            if ($validator instanceof ValueObjectValidator) {
                return $validator;
            }
        }

        return null;
    }

    private function convertAttribute(string $attribute, ValueObjectValidator $validator): ValueObject|BackedEnum|null
    {
        $class = $validator->valueObjectClass ?? throw new LogicException(\sprintf('The %s rule for "%s" in %s has no valueObjectClass.', ValueObjectValidator::class, $attribute, static::class));
        $value = $this->getAttributes([$attribute])[$attribute] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof $class) {
            return $value;
        }

        $mapper = $validator->mapper ?? BaseYii::$container->get(ValueObjectMapper::class);

        try {
            $payload = \is_string($value) && $mapper->isComposite($class) ? CompositeJson::decode($class, $value) : $value;

            return $mapper->fromUntrustedPayload($class, $payload);
        } catch (InvalidValueObjectException $invalid) {
            throw new LogicException(\sprintf('Attribute "%s" of %s does not hold a valid value: was validate() called?', $attribute, static::class), 0, $invalid);
        }
    }
}
