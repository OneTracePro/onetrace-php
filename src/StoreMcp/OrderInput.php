<?php

declare(strict_types=1);

namespace OneTrace\StoreMcp;

/**
 * Arguments of the order tools (contract v2, section 10.2), checked and normalized before the handler runs; a wrong
 * argument answers invalid_input with what is wrong. The Server calls it, so a handler of store_quote_order gets
 * quote() and a handler of store_create_order gets create():
 *
 *   items — list of ['product_id' => string, 'quantity' => int]: 1–20 lines, quantity 1–99, the same product once
 *   (repeated lines are added up);
 *   shipping — the given fields of first_name, last_name, phone, country, city, postcode, address as trimmed strings,
 *   country in upper case (ISO 3166-1 alpha-2); for an order first_name, last_name, country, city and address are
 *   required (the store may require more itself);
 *   shipping_method, payment_method, comment — a trimmed string or null.
 */
final class OrderInput
{
    public const MAX_LINES = 20;
    public const MAX_QUANTITY = 99;
    public const SHIPPING_FIELDS = ['first_name', 'last_name', 'phone', 'country', 'city', 'postcode', 'address'];

    /** Shipping fields an order needs; a quote works with any of them (country, city, postcode are enough). */
    public const ORDER_SHIPPING_FIELDS = ['first_name', 'last_name', 'country', 'city', 'address'];

    private const MAX_FIELD = 255;
    private const MAX_COMMENT = 1000;

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{items: list<array{product_id: string, quantity: int}>, shipping: array<string, string>, shipping_method: string|null}
     */
    public static function quote(array $arguments): array
    {
        return [
            'items' => self::items($arguments['items'] ?? null),
            'shipping' => self::shipping($arguments['shipping'] ?? null, false),
            'shipping_method' => self::text($arguments['shipping_method'] ?? null, 'shipping_method', self::MAX_FIELD),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{items: list<array{product_id: string, quantity: int}>, shipping: array<string, string>, shipping_method: string, payment_method: string|null, comment: string|null}
     */
    public static function create(array $arguments): array
    {
        $method = self::text($arguments['shipping_method'] ?? null, 'shipping_method', self::MAX_FIELD);

        if ($method === null) {
            throw self::invalid('shipping_method is required.');
        }

        return [
            'items' => self::items($arguments['items'] ?? null),
            'shipping' => self::shipping($arguments['shipping'] ?? null, true),
            'shipping_method' => $method,
            'payment_method' => self::text($arguments['payment_method'] ?? null, 'payment_method', self::MAX_FIELD),
            'comment' => self::text($arguments['comment'] ?? null, 'comment', self::MAX_COMMENT),
        ];
    }

    /**
     * @param mixed $items
     *
     * @return list<array{product_id: string, quantity: int}>
     */
    public static function items($items): array
    {
        if (!\is_array($items) || $items === [] || array_keys($items) !== range(0, \count($items) - 1)) {
            throw self::invalid('items must be a list of products with product_id and quantity.');
        }

        $quantities = [];

        foreach ($items as $index => $item) {
            $id = \is_array($item) && (\is_string($item['product_id'] ?? null) || \is_int($item['product_id'] ?? null)) ? trim((string) $item['product_id']) : '';
            $quantity = \is_array($item) ? self::quantity($item['quantity'] ?? null) : null;

            if ($id === '' || \strlen($id) > self::MAX_FIELD) {
                throw self::invalid(sprintf('items[%d].product_id must be a product id.', $index));
            }

            if ($quantity === null) {
                throw self::invalid(sprintf('items[%d].quantity must be a whole number from 1 to %d.', $index, self::MAX_QUANTITY));
            }

            $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;

            if ($quantities[$id] > self::MAX_QUANTITY) {
                throw self::invalid(sprintf('At most %d of one product in an order.', self::MAX_QUANTITY));
            }
        }

        if (\count($quantities) > self::MAX_LINES) {
            throw self::invalid(sprintf('At most %d products in an order.', self::MAX_LINES));
        }

        $lines = [];

        foreach ($quantities as $id => $quantity) {
            $lines[] = ['product_id' => (string) $id, 'quantity' => $quantity];
        }

        return $lines;
    }

    /**
     * @param mixed $shipping
     *
     * @return array<string, string>
     */
    public static function shipping($shipping, bool $forOrder): array
    {
        if ($shipping === null && !$forOrder) {
            return [];
        }

        if (!\is_array($shipping) || ($shipping !== [] && array_keys($shipping) === range(0, \count($shipping) - 1))) {
            throw self::invalid('shipping must be an object with the delivery address.');
        }

        $fields = [];

        foreach (self::SHIPPING_FIELDS as $field) {
            $value = self::text($shipping[$field] ?? null, 'shipping.' . $field, self::MAX_FIELD);

            if ($value !== null) {
                $fields[$field] = $value;
            }
        }

        if (isset($fields['country'])) {
            $fields['country'] = strtoupper($fields['country']);

            if (preg_match('/^[A-Z]{2}$/', $fields['country']) !== 1) {
                throw self::invalid('shipping.country must be a two-letter country code (ISO 3166-1 alpha-2).');
            }
        }

        if ($forOrder) {
            $missing = array_values(array_diff(self::ORDER_SHIPPING_FIELDS, array_keys($fields)));

            if ($missing !== []) {
                throw self::invalid('The delivery address needs ' . implode(', ', $missing) . '.');
            }
        }

        return $fields;
    }

    /**
     * @param mixed $value
     */
    private static function quantity($value): ?int
    {
        if (\is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        $quantity = \is_int($value) ? $value : (\is_string($value) && ctype_digit($value) ? (int) $value : null);

        return $quantity !== null && $quantity >= 1 && $quantity <= self::MAX_QUANTITY ? $quantity : null;
    }

    /**
     * A trimmed string, null when not given or empty.
     *
     * @param mixed $value
     */
    private static function text($value, string $name, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
            throw self::invalid($name . ' must be a string.');
        }

        $text = trim((string) $value);

        if (preg_match('//u', $text) !== 1) {
            throw self::invalid($name . ' must be UTF-8 text.');
        }

        if ((int) preg_match_all('/./us', $text) > $max) {
            throw self::invalid(sprintf('%s must be at most %d characters.', $name, $max));
        }

        return $text === '' ? null : $text;
    }

    private static function invalid(string $message): ToolError
    {
        return new ToolError(ToolError::INVALID_INPUT, $message);
    }
}
