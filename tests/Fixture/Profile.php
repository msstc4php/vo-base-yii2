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
use MSSTC4PHP\ValueObject\Temporal\DateRange;
use MSSTC4PHP\ValueObject\Yii2\Behavior\ValueObjectAttributeBehavior;
use MSSTC4PHP\ValueObject\Yii2\Behavior\ValueObjectAttributes;
use MSSTC4PHP\ValueObject\Yii2\Validator\ValueObjectValidator;
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
 * @property DateRange|string|null $period
 */
final class Profile extends ActiveRecord
{
    use ValueObjectAttributes;

    public const array MAP = [
        'email' => EmailAddress::class,
        'age' => PositiveInt::class,
        'share' => Percentage::class,
        'country' => CountryCode::class,
        'priority' => Priority::class,
        'color' => HexColor::class,
        'range' => IntRange::class,
        'label' => BoundedString::class,
        'period' => DateRange::class,
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
            'period' => 'text',
        ])->execute();
        $db->createCommand()->createIndex('profile_email', 'profile', 'email', true)->execute();
    }

    #[Override]
    public static function tableName(): string
    {
        return 'profile';
    }

    /**
     * @return list<array{0: string, 1: class-string, valueObjectClass: class-string}>
     */
    #[Override]
    public function rules(): array
    {
        return [['email', ValueObjectValidator::class, 'valueObjectClass' => EmailAddress::class]];
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
