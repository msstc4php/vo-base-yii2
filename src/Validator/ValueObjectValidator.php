<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Validator;

use BackedEnum;
use MSSTC4PHP\ValueObject\Contract\ValueObject;
use MSSTC4PHP\ValueObject\Exception\InvalidValueObjectException;
use MSSTC4PHP\ValueObject\Payload\ValueObjectMapper;
use Override;
use yii\base\InvalidConfigException;
use yii\BaseYii;
use yii\validators\Validator;

/**
 * Delegates validation to the value object: the attribute is valid when the value
 * object accepts it. Usage in rules():
 * `['email', ValueObjectValidator::class, 'valueObjectClass' => EmailAddress::class]`.
 *
 * Emptiness is the job of `required` (or skipOnEmpty): null and '' always pass.
 * Server-side only; no client-side validation is generated.
 */
final class ValueObjectValidator extends Validator
{
    /**
     * Not named `class`: Validator::createValidator() overwrites the `class` key of
     * a rule with the validator class itself.
     *
     * @var class-string<ValueObject|BackedEnum>|null
     */
    public ?string $valueObjectClass = null;

    /**
     * Pass one built with your own codecs to validate a custom composite.
     */
    public ?ValueObjectMapper $mapper = null;

    /**
     * @var class-string<ValueObject|BackedEnum>
     */
    private string $class;

    private ValueObjectMapper $resolvedMapper;

    private string $errorMessage;

    /**
     * @throws InvalidConfigException when valueObjectClass is missing, unsupported, or not safe to build from user input
     */
    #[Override]
    public function init(): void
    {
        parent::init();

        $this->resolvedMapper = $this->mapper ?? new ValueObjectMapper();
        $class = $this->valueObjectClass;
        if ($class === null || !$this->resolvedMapper->supports($class)) {
            throw new InvalidConfigException(\sprintf('%s::$valueObjectClass must name a value object class the payload mapper supports, %s given.', self::class, $class ?? 'null'));
        }

        // A payload that carries its own constraints (BoundedString's bounds) would let the input choose them.
        if (!$this->resolvedMapper->supportsUntrusted($class)) {
            throw new InvalidConfigException(\sprintf('%s cannot validate %s from user input: its payload carries its own constraints. For BoundedString, validate the length with Yii\'s "string" validator or use NonEmptyString.', self::class, $class));
        }

        $this->class = $class;
        $this->errorMessage = $this->message ?? BaseYii::t('yii', '{attribute} is invalid.');
    }

    /**
     * @return array{string, array{}}|null
     */
    #[Override]
    protected function validateValue($value): ?array
    {
        if ($value === null || $value === '' || $value instanceof $this->class) {
            return null;
        }

        try {
            $this->resolvedMapper->fromUntrustedPayload($this->class, $value);
        } catch (InvalidValueObjectException) {
            return [$this->errorMessage, []];
        }

        return null;
    }
}
