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

    /** Description characters sent to the platform: the AI answers questions about the product from the start of it. */
    public const DESCRIPTION_MAX = 5000;

    /**
     * @param string|int $id
     * @param array<string|int> $categoryIds the deepest category first
     * @param array<string, mixed> $params scalar attributes; empty, null and non-scalar values are skipped
     * @param int|null $stock units in stock; the platform uses it only when stock levels are on in its catalog
     *                        settings (then zero takes the product off sale); null when the shop does not track stock
     * @param string|null $description product description, HTML or text: the AI answers shoppers' questions about the
     *                                 product from it («Ask AI» on the product page); turned into text, at most 5000
     *                                 characters
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
        array $params = [],
        ?int $stock = null,
        ?string $description = null
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
            'stock' => $stock !== null ? max(0, $stock) : null,
            'description' => self::description($description),
        ], static function ($value): bool {
            return $value !== null;
        });
    }

    /**
     * @param string|int $id
     * @param string|int|null $parentId
     *
     * @return array<string, mixed>
     */
    public static function category($id, string $name, $parentId = null): array
    {
        $category = ['id' => (string) $id, 'name' => trim($name) !== '' ? trim($name) : (string) $id];

        if ($parentId !== null && (string) $parentId !== '' && (string) $parentId !== '0') {
            $category['parent_id'] = (string) $parentId;
        }

        return $category;
    }

    /**
     * A translation of the product for another language of the store (catalog languages of the platform): the
     * visitor and the emails of that language see this name and link. Empty values are skipped.
     *
     * @param array<string, mixed> $item CatalogItem::make()
     * @param array<string, mixed> $params translated attribute values
     * @param string|null $description translated description, HTML or text
     *
     * @return array<string, mixed>
     */
    public static function translate(array $item, string $language, ?string $name, ?string $url = null, array $params = [], ?string $description = null): array
    {
        $tag = Customer::languageTag($language);
        $params = array_slice(array_filter($params, static function ($value): bool {
            return $value !== null && $value !== '' && is_scalar($value);
        }), 0, self::MAX_PARAMS, true);
        $text = array_filter([
            'name' => $name !== null && trim($name) !== '' ? trim($name) : null,
            'url' => self::url($url),
            'params' => $params !== [] ? $params : null,
            'description' => self::description($description),
        ], static function ($value): bool {
            return $value !== null;
        });

        if ($tag !== null && $text !== []) {
            $translations = isset($item['translations']) && \is_array($item['translations']) ? $item['translations'] : [];
            $translations[$tag] = $text;
            $item['translations'] = $translations;
        }

        return $item;
    }

    /**
     * A translation of the category name.
     *
     * @param array<string, mixed> $category CatalogItem::category()
     *
     * @return array<string, mixed>
     */
    public static function translateCategory(array $category, string $language, string $name): array
    {
        $tag = Customer::languageTag($language);

        if ($tag !== null && trim($name) !== '') {
            $translations = isset($category['translations']) && \is_array($category['translations']) ? $category['translations'] : [];
            $translations[$tag] = ['name' => trim($name)];
            $category['translations'] = $translations;
        }

        return $category;
    }

    /**
     * Description as text: line breaks for paragraphs and list items, tags and entities removed, spaces collapsed,
     * at most DESCRIPTION_MAX characters; empty — null.
     */
    public static function description(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $text = preg_replace(['~<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>~i', '~<li\b[^>]*>~i'], ["\n", "\n• "], $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace(['~[ \t\x{00A0}]+~u', '~\s*\n\s*~u'], [' ', "\n"], $text) ?? $text);

        if ($text === '') {
            return null;
        }

        // Without mbstring: the SDK does not require it.
        return preg_match('~^.{0,' . self::DESCRIPTION_MAX . '}~su', $text, $match) === 1 ? $match[0] : $text;
    }

    private static function url(?string $url): ?string
    {
        return $url !== null && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }
}
