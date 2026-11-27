<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Fixture;

use MSSTC4PHP\ValueObject\Yii2\Behavior\ValueObjectAttributeBehavior;
use MSSTC4PHP\ValueObject\Yii2\Behavior\ValueObjectAttributes;
use Override;
use yii\db\ActiveRecord;

/**
 * The profile table with `name` mapped to the abstract Code.
 *
 * @property int|null $id
 * @property Code|string|null $name
 */
final class CodeProfile extends ActiveRecord
{
    use ValueObjectAttributes;

    #[Override]
    public static function tableName(): string
    {
        return 'profile';
    }

    /**
     * @return array<string, array{class: class-string, attributes: array<string, class-string>}>
     */
    #[Override]
    public function behaviors(): array
    {
        return ['valueObjects' => ['class' => ValueObjectAttributeBehavior::class, 'attributes' => ['name' => Code::class]]];
    }
}
