<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Fixture;

enum Level: string
{
    case Low = 'low';
    case High = 'high';
}
