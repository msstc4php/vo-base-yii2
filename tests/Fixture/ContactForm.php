<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Fixture;

use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use MSSTC4PHP\ValueObject\Payload\ValueObjectMapper;
use MSSTC4PHP\ValueObject\Primitive\IntRange;
use MSSTC4PHP\ValueObject\Yii2\Model\ValueObjectModel;
use MSSTC4PHP\ValueObject\Yii2\Validator\ValueObjectValidator;
use Override;
use yii\base\Model;

final class ContactForm extends Model
{
    use ValueObjectModel;

    public mixed $email = null;

    public mixed $range = null;

    public mixed $priority = null;

    public mixed $size = null;

    public mixed $name = null;

    /**
     * @return list<array<array-key, mixed>>
     */
    #[Override]
    public function rules(): array
    {
        return [
            ['email', ValueObjectValidator::class, 'valueObjectClass' => EmailAddress::class],
            ['range', ValueObjectValidator::class, 'valueObjectClass' => IntRange::class],
            ['priority', ValueObjectValidator::class, 'valueObjectClass' => Priority::class],
            ['size', ValueObjectValidator::class, 'valueObjectClass' => Size::class, 'mapper' => new ValueObjectMapper([new SizeCodec()])],
            ['name', 'string'],
        ];
    }
}
