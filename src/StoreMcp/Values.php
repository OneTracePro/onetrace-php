<?php

declare(strict_types=1);

namespace OneTrace\StoreMcp;

/**
 * @internal Scalars from JSON and server variables, with a default for anything else.
 */
final class Values
{
    /**
     * @param mixed $value
     */
    public static function string($value, string $default = ''): string
    {
        return \is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param mixed $value
     */
    public static function int($value, int $default = 0): int
    {
        return \is_int($value) || (\is_string($value) && ctype_digit($value)) ? (int) $value : $default;
    }
}
