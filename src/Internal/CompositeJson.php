<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Internal;

use JsonException;
use MSSTC4PHP\ValueObject\Exception\InvalidPayloadException;

/**
 * The JSON text form of a composite payload, shared by the validator and the
 * behavior so both accept the same input.
 *
 * @internal
 */
final class CompositeJson
{
    private function __construct()
    {
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws InvalidPayloadException when $json is not a JSON object or array
     */
    public static function decode(string $class, string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException $invalidJson) {
            throw new InvalidPayloadException(\sprintf('A %s payload is not valid JSON: %s', $class, $invalidJson->getMessage()), $invalidJson->getCode(), previous: $invalidJson);
        }

        return \is_array($decoded) ? $decoded : throw new InvalidPayloadException(\sprintf('A %s payload must be a JSON object, %s given.', $class, get_debug_type($decoded)));
    }

    /**
     * @param array<string, scalar|null> $payload
     *
     * @throws InvalidPayloadException when the payload cannot be encoded (invalid UTF-8, say)
     */
    public static function encode(string $class, array $payload): string
    {
        try {
            return json_encode($payload, \JSON_THROW_ON_ERROR);
        } catch (JsonException $unencodable) {
            throw new InvalidPayloadException(\sprintf('A %s payload cannot be encoded as JSON: %s', $class, $unencodable->getMessage()), $unencodable->getCode(), previous: $unencodable);
        }
    }
}
