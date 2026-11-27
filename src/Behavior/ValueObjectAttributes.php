<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Behavior;

use MSSTC4PHP\ValueObject\Yii2\Internal\RecordBehaviors;
use Override;
use Throwable;
use yii\db\BaseActiveRecord;

/**
 * For an ActiveRecord using ValueObjectAttributeBehavior: puts the value objects
 * back when an insert or update fails or is vetoed, which afterSave never sees,
 * and dehydrates around updateAttributes(), which skips the save events.
 *
 * @phpstan-require-extends BaseActiveRecord
 */
trait ValueObjectAttributes
{
    /**
     * @param bool $runValidation
     * @param array<mixed>|null $attributes as wide as ActiveRecord::insert() declares it
     *
     * @throws Throwable whatever the insert throws, after the value objects are back
     */
    #[Override]
    public function insert($runValidation = true, $attributes = null): bool
    {
        try {
            return parent::insert($runValidation, $attributes);
        } finally {
            RecordBehaviors::hydrate($this);
        }
    }

    /**
     * @param bool $runValidation
     * @param array<mixed>|null $attributeNames as wide as ActiveRecord::update() declares it
     *
     * @throws Throwable whatever the update throws, after the value objects are back
     */
    #[Override]
    public function update($runValidation = true, $attributeNames = null): false|int
    {
        try {
            return parent::update($runValidation, $attributeNames);
        } finally {
            RecordBehaviors::hydrate($this);
        }
    }

    /**
     * updateAttributes() skips beforeSave/afterSave, so the payloads are written
     * and the value objects restored here.
     *
     * @param array<mixed> $attributes names, or name => value pairs, as BaseActiveRecord::updateAttributes() takes them
     *
     * @throws Throwable whatever the update throws, after the value objects are back
     */
    #[Override]
    public function updateAttributes($attributes): int
    {
        $names = [];
        foreach ($attributes as $name => $value) {
            if (\is_int($name)) {
                $names[] = $value;
            } else {
                $this->setAttribute($name, $value);
                $names[] = $name;
            }
        }

        try {
            RecordBehaviors::dehydrate($this);

            return parent::updateAttributes($names);
        } finally {
            RecordBehaviors::hydrate($this);
        }
    }
}
