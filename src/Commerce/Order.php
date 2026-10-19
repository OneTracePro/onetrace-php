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
     * The `order` of an order email (Emails::send()): the order with its lines (see LineItem::withDisplay()) and
     * $extra: number (shown to the customer, the id by default), url (the order page), refund_amount, carrier,
     * tracking_number, tracking_url, estimated_delivery (DateTimeInterface or Y-m-d), reason (cancellation or refund),
     * note, payment_instructions, shipping_address and billing_address (see address()). Empty values are left out.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function emailData(array $extra = []): array
    {
        $data = [
            'order_id' => $this->id,
            'number' => $this->id,
            'created_at' => $this->placedAt->format(\DateTimeInterface::ATOM),
            'currency' => $this->currency,
            'amount' => $this->amount,
        ] + $this->details;

        foreach ($extra as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format($key === 'estimated_delivery' ? 'Y-m-d' : \DateTimeInterface::ATOM);
            }

            if (in_array($key, ['shipping_address', 'billing_address'], true)) {
                $value = is_array($value) ? self::address($value) : null;
            } elseif ($key === 'refund_amount') {
                $value = is_numeric($value) ? round(max(0.0, (float) $value), 4) : null;
            } elseif (in_array($key, ['url', 'tracking_url'], true)) {
                $value = is_string($value) && self::isLink(trim($value)) ? trim($value) : null;
            } elseif (in_array($key, ['number', 'carrier', 'tracking_number', 'estimated_delivery', 'reason', 'note', 'payment_instructions'], true)) {
                $value = is_scalar($value) && trim((string) $value) !== '' ? self::cut((string) $value, in_array($key, ['number', 'carrier', 'tracking_number'], true) ? 100 : 2000) : null;
            } else {
                continue;
            }

            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        $data['products'] = array_map(static function (LineItem $line): array {
            return $line->emailData();
        }, $this->lines);

        return $data;
    }

    /**
     * An address of an order email: name, company, address1, address2, city, region, postcode, country, phone;
     * empty values are left out, null when nothing is left.
     *
     * @param array<mixed> $address
     *
     * @return array<string, string>|null
     */
    public static function address(array $address): ?array
    {
        $result = [];

        foreach (['name', 'company', 'address1', 'address2', 'city', 'region', 'postcode', 'country', 'phone'] as $key) {
            if (isset($address[$key]) && is_scalar($address[$key]) && trim((string) $address[$key]) !== '') {
                $result[$key] = self::cut((string) $address[$key], 200);
            }
        }

        return $result !== [] ? $result : null;
    }

    private static function cut(string $value, int $max): string
    {
        $value = trim($value);

        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    /**
     * @internal
     */
    public static function isLink(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $value) === 1;
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
