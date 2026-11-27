<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Fixture;

use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use MSSTC4PHP\ValueObject\Yii2\Model\ValueObjectModel;
use MSSTC4PHP\ValueObject\Yii2\Validator\ValueObjectValidator;
use Override;
use yii\base\Model;

/**
 * A non-public property and a getter of the same name that disagree: the rule validates the getter's value.
 */
final class GetterForm extends Model
{
    use ValueObjectModel;

    private string $email = 'raw@example.com';

    public function getEmail(): string
    {
        return 'getter@example.com';
    }

    public function rawEmail(): string
    {
        return $this->email;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    #[Override]
    public function rules(): array
    {
        return [['email', ValueObjectValidator::class, 'valueObjectClass' => EmailAddress::class]];
    }
}
