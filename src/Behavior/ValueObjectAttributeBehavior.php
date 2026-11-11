<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Behavior;

use BackedEnum;
use JsonException;
use MSSTC4PHP\ValueObject\Contract\ValueObject;
use MSSTC4PHP\ValueObject\Exception\InvalidPayloadException;
use MSSTC4PHP\ValueObject\Exception\InvalidValueObjectException;
use MSSTC4PHP\ValueObject\Payload\ValueObjectMapper;
use Override;
use yii\base\Behavior;
use yii\base\InvalidConfigException;
use yii\db\ActiveRecord;
use yii\db\BaseActiveRecord;

/**
 * Exposes ActiveRecord attributes as value objects while the database keeps
 * scalars: hydrates after find and refresh, writes the payload before insert and
 * update, and hydrates again afterwards.
 *
 * Old attributes follow the same cycle, and an unchanged attribute shares one
 * instance with its old value, so getDirtyAttributes() stays meaningful outside
 * save() and compares payloads, by value, inside it.
 *
 * A composite is stored as JSON text, or as an array for a column Yii itself
 * maps to an array (a JSON column on MySQL or PostgreSQL).
 *
 * @extends Behavior<BaseActiveRecord>
 */
final class ValueObjectAttributeBehavior extends Behavior
{
    /**
     * @var array<string, class-string<ValueObject|BackedEnum>> attribute name => value object class
     */
    public array $attributes = [];

    /**
     * Pass one built with your own codecs to map a custom composite.
     */
    public ?ValueObjectMapper $mapper = null;

    private BaseActiveRecord $record;

    private ValueObjectMapper $resolvedMapper;

    /**
     * @return array<string, string>
     */
    #[Override]
    public function events(): array
    {
        return [
            BaseActiveRecord::EVENT_AFTER_FIND => 'hydrate',
            BaseActiveRecord::EVENT_AFTER_REFRESH => 'hydrate',
            BaseActiveRecord::EVENT_BEFORE_INSERT => 'dehydrate',
            BaseActiveRecord::EVENT_BEFORE_UPDATE => 'dehydrate',
            BaseActiveRecord::EVENT_AFTER_INSERT => 'hydrate',
            BaseActiveRecord::EVENT_AFTER_UPDATE => 'hydrate',
        ];
    }

    /**
     * @throws InvalidConfigException when the owner is not an ActiveRecord or a mapped class is not supported
     */
    #[Override]
    public function attach($owner): void
    {
        if (!$owner instanceof BaseActiveRecord) {
            throw new InvalidConfigException(\sprintf('%s attaches to a %s, %s given.', self::class, BaseActiveRecord::class, get_debug_type($owner)));
        }

        $mapper = $this->mapper ?? new ValueObjectMapper();
        foreach ($this->attributes as $name => $class) {
            if (!\is_string($name) || !\is_string($class) || !$mapper->supports($class)) {
                throw new InvalidConfigException(\sprintf('%s::$attributes must map attribute names to value object classes the payload mapper supports; "%s" => %s is not.', self::class, $name, \is_string($class) ? $class : get_debug_type($class)));
            }
        }

        $this->record = $owner;
        $this->resolvedMapper = $mapper;
        parent::attach($owner);
    }

    /**
     * Column values to value objects; null stays null.
     *
     * @throws InvalidValueObjectException when a stored value is not a valid value object
     */
    public function hydrate(): void
    {
        $old = $this->record->getOldAttributes();
        foreach ($this->attributes as $name => $class) {
            $raw = $this->record->getAttribute($name);
            $value = $this->fromStorage($class, $raw);
            // An attribute left out of a partial select must stay unset, or the next save writes NULL to it.
            if ($value !== null) {
                $this->record->setAttribute($name, $value);
            }

            if (isset($old[$name])) {
                $this->record->setOldAttribute($name, $old[$name] === $raw ? $value : $this->fromStorage($class, $old[$name]));
            }
        }
    }

    /**
     * Value objects to column values. A raw value (a string from load(), say) is
     * untrusted input: it is validated and canonicalised on the way, and refused
     * for a class whose payload carries its own constraints (BoundedString).
     *
     * @throws InvalidValueObjectException when a raw value is not a valid value object
     */
    public function dehydrate(): void
    {
        $old = $this->record->getOldAttributes();
        foreach ($this->attributes as $name => $class) {
            $value = $this->toColumn($name, $this->fromInput($name, $class, $this->record->getAttribute($name)));
            if ($value !== null) {
                $this->record->setAttribute($name, $value);
            }

            if (isset($old[$name])) {
                $this->record->setOldAttribute($name, $this->toColumn($name, $this->fromStorage($class, $old[$name])));
            }
        }
    }

    /**
     * @param class-string<ValueObject|BackedEnum> $class
     *
     * @return ValueObject|BackedEnum|null
     *
     * @throws InvalidValueObjectException
     */
    private function fromStorage(string $class, mixed $raw): ?object
    {
        if ($raw === null || $raw instanceof $class) {
            return $raw;
        }

        return $this->resolvedMapper->fromPayload($class, $this->decodeComposite($class, $raw));
    }

    /**
     * @param class-string<ValueObject|BackedEnum> $class
     *
     * @return ValueObject|BackedEnum|null
     *
     * @throws InvalidValueObjectException
     */
    private function fromInput(string $name, string $class, mixed $raw): ?object
    {
        if ($raw === null || $raw instanceof $class) {
            return $raw;
        }

        if (!$this->resolvedMapper->supportsUntrusted($class)) {
            throw new InvalidPayloadException(\sprintf('The "%s" attribute takes a %s instance, %s given: a raw payload would choose its own constraints.', $name, $class, get_debug_type($raw)));
        }

        return $this->resolvedMapper->fromUntrustedPayload($class, $this->decodeComposite($class, $raw));
    }

    /**
     * A composite in a text column arrives as its JSON text.
     *
     * @param class-string<ValueObject|BackedEnum> $class
     *
     * @throws InvalidPayloadException when the text is not valid JSON
     */
    private function decodeComposite(string $class, mixed $raw): mixed
    {
        if (!\is_string($raw) || $this->resolvedMapper->isScalarBacked($class) || is_subclass_of($class, BackedEnum::class)) {
            return $raw;
        }

        try {
            return json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException $invalidJson) {
            throw new InvalidPayloadException(\sprintf('A %s payload is not valid JSON: %s', $class, $invalidJson->getMessage()), $invalidJson->getCode(), previous: $invalidJson);
        }
    }

    /**
     * @return string|int|float|array<string, scalar|null>|null
     */
    private function toColumn(string $name, ValueObject|BackedEnum|null $valueObject): string|int|float|array|null
    {
        if ($valueObject === null) {
            return null;
        }

        $payload = $this->resolvedMapper->toPayload($valueObject);

        return \is_array($payload) && !$this->columnTakesArrays($name) ? json_encode($payload, \JSON_THROW_ON_ERROR) : $payload;
    }

    private function columnTakesArrays(string $name): bool
    {
        return $this->record instanceof ActiveRecord
            && $this->record::getTableSchema()->getColumn($name)?->phpType === 'array';
    }
}
