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

    /** @var array<string, string> sku, image_url, url, options — for order emails only (see emailData()) */
    public $display = [];

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
     * What an order email shows for the line: sku, image_url and url (http/https links) and options (variant options
     * as text, «Size: M, colour: white»). Empty values are left out. Events do not carry these fields.
     *
     * @param array<string, string|null> $display
     */
    public function withDisplay(array $display): self
    {
        $copy = clone $this;

        foreach (['sku' => 100, 'options' => 500, 'image_url' => 2000, 'url' => 2000] as $key => $max) {
            $value = isset($display[$key]) ? trim((string) $display[$key]) : '';

            if ($value === '' || strlen($value) > $max || (in_array($key, ['image_url', 'url'], true) && !Order::isLink($value))) {
                continue;
            }

            $copy->display[$key] = $value;
        }

        return $copy;
    }

    /**
     * The line in Order::emailData(): the event fields without the category, the line total and display fields.
     *
     * @return array<string, string|int|float>
     */
    public function emailData(): array
    {
        $data = $this->toArray();
        unset($data['category_id']);

        return $data + ['total' => round($this->price * $this->quantity, 2)] + $this->display;
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
