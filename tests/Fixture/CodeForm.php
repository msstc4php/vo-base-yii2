<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Fixture;

use MSSTC4PHP\ValueObject\Yii2\Model\ValueObjectModel;
use MSSTC4PHP\ValueObject\Yii2\Validator\ValueObjectValidator;
use Override;
use yii\base\Model;

final class CodeForm extends Model
{
    use ValueObjectModel;

    public mixed $code = null;

    /**
     * @return list<array<array-key, mixed>>
     */
    #[Override]
    public function rules(): array
    {
        return [
            ['code', 'string'],
            ['code', ValueObjectValidator::class, 'valueObjectClass' => Code::class],
        ];
    }
}
