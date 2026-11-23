<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Behavior;

use BackedEnum;
use MSSTC4PHP\ValueObject\Contract\ValueObject;
use MSSTC4PHP\ValueObject\Exception\InvalidPayloadException;
use MSSTC4PHP\ValueObject\Exception\InvalidValueObjectException;
use MSSTC4PHP\ValueObject\Payload\ValueObjectMapper;
use MSSTC4PHP\ValueObject\Yii2\Internal\CompositeJson;
use MSSTC4PHP\ValueObject\Yii2\Internal\DefaultMapper;
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
 * A failed or vetoed save never reaches afterSave: use the ValueObjectAttributes
 * trait on the model, or call hydrate() yourself.
 *
 * A composite is stored as JSON text, or as an array for a column Yii itself
 * maps to an array (a JSON column on MySQL or PostgreSQL).
 *
 * @extends Behavior<BaseActiveRecord>
 *
 * @phpstan-type Payload string|int|float|array<string, scalar|null>
 */
final class ValueObjectAttributeBehavior extends Behavior
{
    /**
     * @var array<string, class-string<ValueObject|BackedEnum>> attribute name => value object class
     */
    public array $attributes = [];

    /**
     * Pass one built with your own codecs to map a custom composite, or define it in the container: without one the
     * container's ValueObjectMapper definition is used, as by the validator, else a plain mapper shared by all.
     */
    public ?ValueObjectMapper $mapper = null;

    private BaseActiveRecord $record;

    private ValueObjectMapper $resolvedMapper;

    private bool $attributesChecked = false;

    /**
     * What the last dehydrate() wrote, so hydrate() restores exactly those
     * instances and never builds a value object from raw input.
     *
     * @var array<string, array{ValueObject|BackedEnum, Payload}>
     */
    private array $written = [];

    /**
     * @var array<string, array{ValueObject|BackedEnum, Payload}>
     */
    private array $writtenOld = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    public function events(): array
    {
        return [
            BaseActiveRecord::EVENT_AFTER_FIND => 'hydrateFromStorage',
            BaseActiveRecord::EVENT_AFTER_REFRESH => 'hydrateFromStorage',
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

        $mapper = $this->mapper ?? DefaultMapper::get();
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
     * Column values to value objects after find and refresh; null stays null.
     *
     * @internal event handler
     *
     * @throws InvalidValueObjectException when a stored value is not a valid value object
     * @throws InvalidConfigException when a mapped name is not an attribute of the model
     */
    public function hydrateFromStorage(): void
    {
        $this->checkAttributes();
        $this->written = [];
        $this->writtenOld = [];
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
     * Value objects to column values before insert and update. A raw value (a
     * string from load(), say) is untrusted input: it is validated and
     * canonicalised on the way, and refused for a class whose payload carries its
     * own constraints (BoundedString).
     *
     * @internal event handler
     *
     * @throws InvalidValueObjectException when a raw value is not a valid value object or a payload cannot be encoded
     * @throws InvalidConfigException when a mapped name is not an attribute of the model
     */
    public function dehydrate(): void
    {
        $this->checkAttributes();
        $this->hydrate();
        $old = $this->record->getOldAttributes();
        foreach ($this->attributes as $name => $class) {
            $valueObject = $this->fromInput($name, $class, $this->record->getAttribute($name));
            if ($valueObject !== null) {
                $payload = $this->toColumn($name, $class, $valueObject);
                $this->written[$name] = [$valueObject, $payload];
                $this->record->setAttribute($name, $payload);
            }

            $oldValueObject = isset($old[$name]) ? $this->fromStorage($class, $old[$name]) : null;
            if ($oldValueObject !== null) {
                $payload = $this->toColumn($name, $class, $oldValueObject);
                $this->writtenOld[$name] = [$oldValueObject, $payload];
                $this->record->setOldAttribute($name, $payload);
            }
        }
    }

    /**
     * Puts back the value objects the last dehydrate() replaced with payloads.
     * Runs after a successful save; call it after a failed or vetoed one when the
     * model does not use the ValueObjectAttributes trait. Idempotent.
     */
    public function hydrate(): void
    {
        foreach ($this->written as $name => [$valueObject, $payload]) {
            if ($this->record->getAttribute($name) === $payload) {
                $this->record->setAttribute($name, $valueObject);
            }
        }

        $old = $this->record->getOldAttributes();
        foreach ($this->attributes as $name => $class) {
            if (!isset($old[$name])) {
                continue;
            }

            // Prefer the current instance, so a saved or unchanged attribute is not dirty.
            foreach ([$this->written[$name] ?? null, $this->writtenOld[$name] ?? null] as $entry) {
                if ($entry !== null && $old[$name] === $entry[1]) {
                    $this->record->setOldAttribute($name, $entry[0]);

                    break;
                }
            }
        }
        $this->written = [];
        $this->writtenOld = [];
    }

    /**
     * Not at attach time: that would load the table schema for every new model,
     * and fail for one built before its table exists (migrations, form-only use).
     *
     * @throws InvalidConfigException when a mapped name is not an attribute of the owner
     */
    private function checkAttributes(): void
    {
        if ($this->attributesChecked) {
            return;
        }

        foreach (array_keys($this->attributes) as $name) {
            if (!$this->record->hasAttribute($name)) {
                throw new InvalidConfigException(\sprintf('%s::$attributes maps "%s", which is not an attribute of %s.', self::class, $name, $this->record::class));
            }
        }

        $this->attributesChecked = true;
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

        $payload = \is_string($raw) && $this->resolvedMapper->isComposite($class) ? CompositeJson::decode($class, $raw) : $raw;

        return $this->resolvedMapper->fromPayload($class, $payload);
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

        $payload = \is_string($raw) && $this->resolvedMapper->isComposite($class) ? CompositeJson::decode($class, $raw) : $raw;

        return $this->resolvedMapper->fromUntrustedPayload($class, $payload);
    }

    /**
     * @return Payload
     *
     * @throws InvalidPayloadException when a composite payload cannot be encoded
     */
    private function toColumn(string $name, string $class, ValueObject|BackedEnum $valueObject): string|int|float|array
    {
        $payload = $this->resolvedMapper->toPayload($valueObject);

        return \is_array($payload) && !$this->columnTakesArrays($name) ? CompositeJson::encode($class, $payload) : $payload;
    }

    private function columnTakesArrays(string $name): bool
    {
        return $this->record instanceof ActiveRecord
            && $this->record::getTableSchema()->getColumn($name)?->phpType === 'array';
    }
}
