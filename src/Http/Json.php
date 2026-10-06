<?php

declare(strict_types=1);

namespace OneTrace\Http;

/**
 * JSON encoding for request bodies: UTF-8 as is, dates as ISO 8601, floats keep their fraction.
 *
 * @internal
 */
final class Json
{
    /**
     * @param mixed $value
     */
    public static function encode($value): string
    {
        try {
            return json_encode(self::normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('The request body cannot be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function normalize($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.vP');
        }

        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::normalize($item);
            }
        }

        return $value;
    }
}
