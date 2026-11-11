<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Fixture;

enum Priority: int
{
    case Low = 1;
    case High = 2;
}
