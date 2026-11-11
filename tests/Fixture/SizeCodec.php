<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Fixture;

use LogicException;
use MSSTC4PHP\ValueObject\Contract\ValueObject;
use MSSTC4PHP\ValueObject\Exception\InvalidPayloadException;
use MSSTC4PHP\ValueObject\Payload\PayloadCodec;
use Override;

/**
 * @implements PayloadCodec<Size>
 */
final readonly class SizeCodec implements PayloadCodec
{
    #[Override]
    public function valueObjectClass(): string
    {
        return Size::class;
    }

    /**
     * @return array{width: int, height: int}
     */
    #[Override]
    public function encode(ValueObject $value): array
    {
        return $value instanceof Size ? $value->jsonSerialize() : throw new LogicException('Not a Size.');
    }

    #[Override]
    public function decode(array $payload): Size
    {
        $width = $payload['width'] ?? null;
        $height = $payload['height'] ?? null;

        return \is_int($width) && \is_int($height) ? new Size($width, $height) : throw new InvalidPayloadException('Expected integer "width" and "height".');
    }
}
