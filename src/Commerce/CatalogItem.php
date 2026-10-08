<?php

declare(strict_types=1);

namespace OneTrace\Commerce;

/**
 * A product for $client->products()->upsert(): the same id as product_id in events, prices in the main currency
 * of the shop, the deepest category first, at most 20 attributes.
 */
final class CatalogItem
{
    /** Attributes per product sent to the platform. */
    public const MAX_PARAMS = 20;

    /**
     * @param string|int $id
     * @param array<string|int> $categoryIds the deepest category first
     * @param array<string, mixed> $params scalar attributes; empty, null and non-scalar values are skipped
     *
     * @return array<string, mixed>
     */
    public static function make(
        $id,
        string $name,
        ?string $url,
        ?string $image,
        ?float $price,
        string $currency,
        bool $available,
        array $categoryIds = [],
        ?float $oldPrice = null,
        ?string $brand = null,
        array $params = []
    ): array {
        $params = array_slice(array_filter($params, static function ($value): bool {
            return $value !== null && $value !== '' && is_scalar($value);
        }), 0, self::MAX_PARAMS, true);

        return array_filter([
            'id' => (string) $id,
            'name' => trim($name) !== '' ? trim($name) : (string) $id,
            'url' => self::url($url),
            'image' => self::url($image),
            'price' => $price !== null ? round(max(0.0, $price), 4) : null,
            'old_price' => $oldPrice !== null && $price !== null && $oldPrice > $price ? round($oldPrice, 4) : null,
            'currency' => strtoupper($currency),
            'available' => $available,
            'category_ids' => $categoryIds !== [] ? array_slice(array_values(array_map('strval', $categoryIds)), 0, 10) : null,
            'brand' => $brand !== null && trim($brand) !== '' ? trim($brand) : null,
            'params' => $params !== [] ? $params : null,
        ], static function ($value): bool {
            return $value !== null;
        });
    }

    /**
     * @param string|int $id
     * @param string|int|null $parentId
     *
     * @return array{id: string, name: string, parent_id?: string}
     */
    public static function category($id, string $name, $parentId = null): array
    {
        $category = ['id' => (string) $id, 'name' => trim($name) !== '' ? trim($name) : (string) $id];

        if ($parentId !== null && (string) $parentId !== '' && (string) $parentId !== '0') {
            $category['parent_id'] = (string) $parentId;
        }

        return $category;
    }

    private static function url(?string $url): ?string
    {
        return $url !== null && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }
}
