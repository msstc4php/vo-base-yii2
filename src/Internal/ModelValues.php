<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Internal;

use BackedEnum;
use LogicException;
use MSSTC4PHP\ValueObject\Contract\ValueObject;
use MSSTC4PHP\ValueObject\Exception\InvalidValueObjectException;
use MSSTC4PHP\ValueObject\Yii2\Validator\ValueObjectValidator;
use yii\base\Model;

/**
 * The bodies of ValueObjectModel::valueObject() and valueObjects(): a trait's private helpers would be replaced,
 * silently, by a model method of the same name.
 *
 * @internal
 */
final class ModelValues
{
    private function __construct()
    {
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|null $class
     *
     * @return ($class is null ? ValueObject|BackedEnum|null : T|null)
     *
     * @throws LogicException when the model has errors, the attribute has no ValueObjectValidator rule for $class, or its value does not convert
     */
    public static function one(Model $model, string $attribute, ?string $class): ?object
    {
        self::assertWithoutErrors($model);
        $validator = self::validatorOf($model, $attribute);
        if (!$validator instanceof ValueObjectValidator) {
            throw new LogicException(\sprintf('Attribute "%s" has no %s rule in %s::rules().', $attribute, ValueObjectValidator::class, $model::class));
        }

        if ($class !== null && !is_a($validator->valueObjectClass ?? '', $class, true)) {
            throw new LogicException(\sprintf('Attribute "%s" of %s is validated as %s, not %s.', $attribute, $model::class, $validator->valueObjectClass ?? 'nothing', $class));
        }

        return self::convert($model, $attribute, $validator);
    }

    /**
     * @return array<string, ValueObject|BackedEnum|null>
     *
     * @throws LogicException when the model has errors or a value does not convert
     */
    public static function all(Model $model): array
    {
        self::assertWithoutErrors($model);

        $objects = [];
        foreach ($model->getActiveValidators() as $validator) {
            if (!$validator instanceof ValueObjectValidator) {
                continue;
            }

            foreach ($validator->getAttributeNames() as $attribute) {
                if (!\is_string($attribute)) {
                    continue;
                }

                $objects[$attribute] = self::convert($model, $attribute, $validator);
            }
        }

        return $objects;
    }

    /**
     * @throws LogicException when the model has errors
     */
    private static function assertWithoutErrors(Model $model): void
    {
        if ($model->hasErrors()) {
            throw new LogicException(\sprintf('%s has validation errors: read value objects only after validate() succeeded.', $model::class));
        }
    }

    private static function validatorOf(Model $model, string $attribute): ?ValueObjectValidator
    {
        foreach ($model->getActiveValidators($attribute) as $validator) {
            if ($validator instanceof ValueObjectValidator) {
                return $validator;
            }
        }

        return null;
    }

    /**
     * @throws LogicException when the value does not convert
     */
    private static function convert(Model $model, string $attribute, ValueObjectValidator $validator): ValueObject|BackedEnum|null
    {
        $class = $validator->valueObjectClass ?? throw new LogicException(\sprintf('The %s rule for "%s" in %s has no valueObjectClass.', ValueObjectValidator::class, $attribute, $model::class));
        // Reason: validators read the attribute the same way, which also covers getter/setter attributes.
        // @phpstan-ignore property.dynamicName
        $value = $model->{$attribute};
        if ($value === null || $value === '' || self::ruleSkipped($model, $validator, $attribute, $value)) {
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
            throw new LogicException(\sprintf('Attribute "%s" of %s does not hold a valid value: was validate() called?', $attribute, $model::class), previous: $invalid);
        }
    }

    private static function ruleSkipped(Model $model, ValueObjectValidator $validator, string $attribute, mixed $value): bool
    {
        if ($validator->skipOnEmpty && $validator->isEmpty($value)) {
            return true;
        }

        return $validator->when !== null && !(bool) \call_user_func($validator->when, $model, $attribute);
    }
}
