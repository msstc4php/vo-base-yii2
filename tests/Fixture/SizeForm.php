<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Fixture;

use MSSTC4PHP\ValueObject\Yii2\Model\ValueObjectModel;
use MSSTC4PHP\ValueObject\Yii2\Validator\ValueObjectValidator;
use Override;
use yii\base\Model;

/**
 * Its rule has no own mapper: Size is known through the container's.
 */
final class SizeForm extends Model
{
    use ValueObjectModel;

    public mixed $size = null;

    /**
     * @return list<array<array-key, mixed>>
     */
    #[Override]
    public function rules(): array
    {
        return [['size', ValueObjectValidator::class, 'valueObjectClass' => Size::class]];
    }
}
