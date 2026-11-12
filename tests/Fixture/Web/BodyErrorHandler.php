<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Web;

use Throwable;
use yii\web\ErrorHandler;

/**
 * Exposes the client-facing error payload Yii builds for an exception.
 */
final class BodyErrorHandler extends ErrorHandler
{
    /**
     * @return array<array-key, mixed>
     */
    public function bodyOf(Throwable $error): array
    {
        return $this->convertExceptionToArray($error);
    }
}
