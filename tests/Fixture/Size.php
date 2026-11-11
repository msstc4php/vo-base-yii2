<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Fixture;

use MSSTC4PHP\ValueObject\Contract\ValueObject;
use Override;

/**
 * A composite the core has no codec for; SizeCodec teaches the mapper.
 */
final readonly class Size implements ValueObject
{
    public function __construct(
        public int $width,
        public int $height,
    ) {
    }

    #[Override]
    public function equals(ValueObject $other): bool
    {
        return $other instanceof self && $other->width === $this->width && $other->height === $this->height;
    }

    /**
     * @return array{width: int, height: int}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['width' => $this->width, 'height' => $this->height];
    }
}
