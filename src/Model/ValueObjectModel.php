<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Model;

use BackedEnum;
use LogicException;
use MSSTC4PHP\ValueObject\Contract\ValueObject;
use MSSTC4PHP\ValueObject\Yii2\Internal\ModelValues;
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
        return ModelValues::one($this, $attribute, $class);
    }

    /**
     * @return array<string, ValueObject|BackedEnum|null> every attribute that has a ValueObjectValidator rule
     *
     * @throws LogicException when the model has errors or a value does not convert
     */
    public function valueObjects(): array
    {
        return ModelValues::all($this);
    }
}
