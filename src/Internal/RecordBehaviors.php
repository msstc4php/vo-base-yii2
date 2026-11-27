<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Internal;

use MSSTC4PHP\ValueObject\Exception\InvalidValueObjectException;
use MSSTC4PHP\ValueObject\Yii2\Behavior\ValueObjectAttributeBehavior;
use yii\base\InvalidConfigException;
use yii\db\BaseActiveRecord;

/**
 * The record-wide calls of the ValueObjectAttributes trait: a trait's private helpers would be replaced, silently, by
 * a model method of the same name.
 *
 * @internal
 */
final class RecordBehaviors
{
    private function __construct()
    {
    }

    /**
     * @throws InvalidValueObjectException when a raw value is not a valid value object or a payload cannot be encoded
     * @throws InvalidConfigException when a mapped name is not an attribute of the model
     */
    public static function dehydrate(BaseActiveRecord $record): void
    {
        foreach ($record->getBehaviors() as $behavior) {
            if ($behavior instanceof ValueObjectAttributeBehavior) {
                $behavior->dehydrate();
            }
        }
    }

    public static function hydrate(BaseActiveRecord $record): void
    {
        foreach ($record->getBehaviors() as $behavior) {
            if ($behavior instanceof ValueObjectAttributeBehavior) {
                $behavior->hydrate();
            }
        }
    }
}
