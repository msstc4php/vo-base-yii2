<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Internal;

use LogicException;
use MSSTC4PHP\ValueObject\Payload\ValueObjectMapper;
use yii\BaseYii;

/**
 * The mapper of a validator, behavior or action without one: the container's definition when there is one (with the
 * application's codecs), else one plain mapper shared by all, instead of a new one built per record.
 *
 * @internal
 */
final class DefaultMapper
{
    private static ?ValueObjectMapper $fallback = null;

    private function __construct()
    {
    }

    /**
     * @throws LogicException when the container's definition builds something else than a ValueObjectMapper
     */
    public static function get(): ValueObjectMapper
    {
        if (!BaseYii::$container->has(ValueObjectMapper::class)) {
            return self::$fallback ??= new ValueObjectMapper();
        }

        $mapper = BaseYii::$container->get(ValueObjectMapper::class);

        return $mapper instanceof ValueObjectMapper ? $mapper : throw new LogicException(\sprintf('The container defines %s as %s.', ValueObjectMapper::class, get_debug_type($mapper)));
    }
}
