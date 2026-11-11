<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Behavior;

use Override;
use Throwable;
use yii\db\BaseActiveRecord;

/**
 * For an ActiveRecord using ValueObjectAttributeBehavior: puts the value objects
 * back when an insert or update fails or is vetoed, which afterSave never sees.
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
            $this->restoreValueObjects();
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
            $this->restoreValueObjects();
        }
    }

    private function restoreValueObjects(): void
    {
        foreach ($this->getBehaviors() as $behavior) {
            if ($behavior instanceof ValueObjectAttributeBehavior) {
                $behavior->hydrate();
            }
        }
    }
}
