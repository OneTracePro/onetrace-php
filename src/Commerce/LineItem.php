<?php

declare(strict_types=1);

namespace OneTrace\Commerce;

/**
 * A product in an event or a line of an order. productId is the id of the product in the catalog (for variants —
 * the parent product), the price is per unit after discounts, in the currency of the order.
 */
final class LineItem
{
    /** @var string */
    public $productId;

    /** @var string */
    public $name;

    /** @var float */
    public $price;

    /** @var int */
    public $quantity;

    /** @var string|null */
    public $variantId;

    /** @var string|null */
    public $categoryId;

    /**
     * @param string|int $productId
     * @param string|int|null $variantId
     * @param string|int|null $categoryId
     */
    public function __construct($productId, string $name, float $price, int $quantity = 1, $variantId = null, $categoryId = null)
    {
        $this->productId = (string) $productId;
        $this->name = trim($name) !== '' ? trim($name) : (string) $productId;
        $this->price = round(max(0.0, $price), 4);
        $this->quantity = max(1, $quantity);
        $this->variantId = $variantId !== null && (string) $variantId !== '' && (string) $variantId !== (string) $productId ? (string) $variantId : null;
        $this->categoryId = $categoryId !== null && (string) $categoryId !== '' ? (string) $categoryId : null;
    }

    /**
     * @return array<string, string|int|float>
     */
    public function toArray(): array
    {
        return array_filter([
            'product_id' => $this->productId,
            'variant_id' => $this->variantId,
            'product_name' => $this->name,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'category_id' => $this->categoryId,
        ], static function ($value): bool {
            return $value !== null;
        });
    }
}
