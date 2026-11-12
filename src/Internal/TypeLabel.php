<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Internal;

/**
 * Client-facing name of a value object type: end users never see a class name.
 *
 * @internal
 */
final class TypeLabel
{
    private function __construct()
    {
    }

    /**
     * EmailAddress becomes "email address".
     *
     * @param class-string $class
     */
    public static function of(string $class): string
    {
        $separator = strrpos($class, '\\');
        $short = $separator === false ? $class : substr($class, $separator + 1);

        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $short));
    }
}
