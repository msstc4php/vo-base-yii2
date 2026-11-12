<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Fixture;

use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use MSSTC4PHP\ValueObject\Yii2\Model\ValueObjectModel;
use MSSTC4PHP\ValueObject\Yii2\Validator\ValueObjectValidator;
use Override;
use yii\base\Model;

final class ConditionalForm extends Model
{
    use ValueObjectModel;

    public mixed $email = null;

    public bool $checkEmail = true;

    public mixed $strictEmail = null;

    private mixed $nickEmail = null;

    public function getNickEmail(): mixed
    {
        return $this->nickEmail;
    }

    public function setNickEmail(mixed $value): void
    {
        $this->nickEmail = $value;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    #[Override]
    public function rules(): array
    {
        return [
            ['email', ValueObjectValidator::class, 'valueObjectClass' => EmailAddress::class, 'when' => static fn (self $model): bool => $model->checkEmail],
            ['strictEmail', ValueObjectValidator::class, 'valueObjectClass' => EmailAddress::class, 'skipOnEmpty' => false],
            ['nickEmail', ValueObjectValidator::class, 'valueObjectClass' => EmailAddress::class],
        ];
    }
}
