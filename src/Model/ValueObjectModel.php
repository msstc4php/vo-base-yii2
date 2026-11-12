<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Model;

use BackedEnum;
use LogicException;
use MSSTC4PHP\ValueObject\Contract\ValueObject;
use MSSTC4PHP\ValueObject\Exception\InvalidValueObjectException;
use MSSTC4PHP\ValueObject\Yii2\Internal\CompositeJson;
use MSSTC4PHP\ValueObject\Yii2\Validator\ValueObjectValidator;
use yii\base\Model;

/**
 * Reads the validated attributes of a form or REST model as value objects. The class
 * and the mapper come from the attribute's `ValueObjectValidator` rule, so rule and
 * conversion cannot disagree. Call it after `validate()`: a model with errors, an
 * attribute without the rule, or a value that does not convert is a LogicException.
 *
 * An attribute the rule did not check, because it is empty for the rule (`skipOnEmpty`,
 * so `[]` too) or its `when` condition is false, is `null`: unvalidated input is never converted.
 *
 * @phpstan-require-extends Model
 */
trait ValueObjectModel
{
    /**
     * @template T of object
     *
     * @param class-string<T>|null $class asserts the rule's class, so the result is typed
     *
     * @return ($class is null ? ValueObject|BackedEnum|null : T|null) null for an empty or unchecked attribute
     *
     * @throws LogicException when the model has errors, the attribute has no ValueObjectValidator rule for $class, or its value does not convert
     */
    public function valueObject(string $attribute, ?string $class = null): ?object
    {
        $this->assertWithoutErrors();
        $validator = $this->valueObjectValidatorOf($attribute);
        if (!$validator instanceof ValueObjectValidator) {
            throw new LogicException(\sprintf('Attribute "%s" has no %s rule in %s::rules().', $attribute, ValueObjectValidator::class, static::class));
        }

        if ($class !== null && !is_a($validator->valueObjectClass ?? '', $class, true)) {
            throw new LogicException(\sprintf('Attribute "%s" of %s is validated as %s, not %s.', $attribute, static::class, $validator->valueObjectClass ?? 'nothing', $class));
        }

        return $this->convertAttribute($attribute, $validator);
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
        // Reason: validators read the attribute the same way, which also covers getter/setter attributes.
        // @phpstan-ignore property.dynamicName
        $value = $this->{$attribute};
        if ($value === null || $value === '' || $this->valueObjectRuleSkipped($validator, $attribute, $value)) {
            return null;
        }

        if ($value instanceof $class) {
            return $value;
        }

        $mapper = $validator->resolvedMapper();

        try {
            $payload = \is_string($value) && $mapper->isComposite($class) ? CompositeJson::decode($class, $value) : $value;

            return $mapper->fromUntrustedPayload($class, $payload);
        } catch (InvalidValueObjectException $invalid) {
            throw new LogicException(\sprintf('Attribute "%s" of %s does not hold a valid value: was validate() called?', $attribute, static::class), 0, $invalid);
        }
    }

    private function valueObjectRuleSkipped(ValueObjectValidator $validator, string $attribute, mixed $value): bool
    {
        if ($validator->skipOnEmpty && $validator->isEmpty($value)) {
            return true;
        }

        return $validator->when !== null && !(bool) \call_user_func($validator->when, $this, $attribute);
    }
}
