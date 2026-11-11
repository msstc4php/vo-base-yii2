<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Fixture;

use MSSTC4PHP\ValueObject\Format\HexColor;
use MSSTC4PHP\ValueObject\I18n\CountryCode;
use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use MSSTC4PHP\ValueObject\Primitive\BoundedString;
use MSSTC4PHP\ValueObject\Primitive\IntRange;
use MSSTC4PHP\ValueObject\Primitive\Percentage;
use MSSTC4PHP\ValueObject\Primitive\PositiveInt;
use MSSTC4PHP\ValueObject\Yii2\Behavior\ValueObjectAttributeBehavior;
use Override;
use yii\db\ActiveRecord;
use yii\db\Connection;

/**
 * @property int|null $id
 * @property string|null $name
 * @property EmailAddress|string|null $email
 * @property PositiveInt|int|string|null $age
 * @property Percentage|float|null $share
 * @property CountryCode|string|null $country
 * @property Priority|int|null $priority
 * @property HexColor|string|null $color
 * @property IntRange|string|null $range
 * @property BoundedString|string|null $label
 */
final class Profile extends ActiveRecord
{
    public const array MAP = [
        'email' => EmailAddress::class,
        'age' => PositiveInt::class,
        'share' => Percentage::class,
        'country' => CountryCode::class,
        'priority' => Priority::class,
        'color' => HexColor::class,
        'range' => IntRange::class,
        'label' => BoundedString::class,
    ];

    public static function createTable(Connection $db): void
    {
        $db->createCommand()->createTable('profile', [
            'id' => 'pk',
            'name' => 'string',
            'email' => 'string',
            'age' => 'integer',
            'share' => 'double',
            'country' => 'string',
            'priority' => 'integer',
            'color' => 'string',
            'range' => 'text',
            'label' => 'text',
        ])->execute();
    }

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
        return ['valueObjects' => ['class' => ValueObjectAttributeBehavior::class, 'attributes' => self::MAP]];
    }
}
