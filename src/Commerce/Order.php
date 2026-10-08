<?php

declare(strict_types=1);

namespace OneTrace\Commerce;

/**
 * An order of the shop: the number the customer sees, the total to pay and its parts, all in the order currency.
 */
final class Order
{
    /** @var string */
    public $id;

    /** @var float */
    public $amount;

    /** @var string ISO 4217 */
    public $currency;

    /** @var \DateTimeInterface when the order was placed */
    public $placedAt;

    /** @var Customer */
    public $customer;

    /** @var list<LineItem> */
    public $lines = [];

    /** @var array<string, float|string> subtotal, shipping, tax, discount, coupon, payment_method, shipping_method */
    public $details = [];

    /**
     * @param string|int $id
     * @param array<LineItem> $lines
     */
    public function __construct($id, float $amount, string $currency, \DateTimeInterface $placedAt, Customer $customer, array $lines = [])
    {
        $this->id = (string) $id;
        $this->amount = round(max(0.0, $amount), 4);
        $this->currency = strtoupper($currency);
        $this->placedAt = $placedAt;
        $this->customer = $customer;
        $this->lines = array_values($lines);
    }

    /**
     * Parts of the total: subtotal, shipping, tax, discount (numbers) and coupon, payment_method, shipping_method
     * (strings). Empty values are left out.
     *
     * @param array<string, float|int|string|null> $details
     */
    public function with(array $details): self
    {
        $copy = clone $this;

        foreach ($details as $key => $value) {
            if (in_array($key, ['subtotal', 'shipping', 'tax', 'discount'], true) && is_numeric($value)) {
                $copy->details[$key] = round(max(0.0, (float) $value), 4);
            } elseif (in_array($key, ['coupon', 'payment_method', 'shipping_method'], true) && is_string($value) && trim($value) !== '') {
                $copy->details[$key] = trim($value);
            }
        }

        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return ['order_id' => $this->id, 'amount' => $this->amount, 'currency' => $this->currency] + $this->details + [
            'products' => array_map(static function (LineItem $line): array {
                return $line->toArray();
            }, $this->lines),
        ];
    }
}
