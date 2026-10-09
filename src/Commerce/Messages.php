<?php

declare(strict_types=1);

namespace OneTrace\Commerce;

/**
 * Messages of e-commerce plugins by the common contract (integrations/ecommerce, schema in
 * resources/ecommerce-events.schema.json): send them with $client->events()->batch() and a secret key.
 *
 *     $messages = new Messages('woocommerce', home_url());
 *     $client->events()->batch([
 *         $messages->identify($order->customer, [Messages::subscribed('email')]),
 *         $messages->orderCompleted($order),
 *     ]);
 *
 * Order events get a deterministic messageId ("{platform}:{shop}:{order}:{event}"), so sending the same order
 * event again after a failure is dropped by the platform as a duplicate.
 */
final class Messages
{
    public const ORDER_COMPLETED = 'order_completed';

    public const ORDER_PAID = 'order_paid';

    public const ORDER_CANCELLED = 'order_cancelled';

    public const ORDER_REFUNDED = 'order_refunded';

    /** @var string */
    private $platform;

    /** @var string short hash of the shop address: one project can have several shops */
    private $shop;

    /**
     * @param string $platform plugin platform, e.g. "woocommerce"
     * @param string $shopUrl address of the shop
     */
    public function __construct(string $platform, string $shopUrl)
    {
        $this->platform = preg_replace('/[^a-z0-9_]/', '', strtolower($platform)) ?: 'shop';
        $host = parse_url($shopUrl, PHP_URL_HOST);
        $this->shop = substr(sha1(strtolower(is_string($host) ? $host . (string) parse_url($shopUrl, PHP_URL_PATH) : $shopUrl)), 0, 8);
    }

    /**
     * A subscription given in a form: subscribed('email'), subscribed('sms', 'promo').
     *
     * @return array{channel: string, status: string, topic?: string}
     */
    public static function subscribed(string $channel, ?string $topic = null): array
    {
        return self::consent($channel, 'subscribed', $topic);
    }

    /**
     * @return array{channel: string, status: string, topic?: string}
     */
    public static function unsubscribed(string $channel, ?string $topic = null): array
    {
        return self::consent($channel, 'unsubscribed', $topic);
    }

    /**
     * Customer data on sign-up, login, profile change and checkout; consents only with a secret key.
     *
     * @param array<array{channel: string, status: string, topic?: string}> $consents
     *
     * @return array<string, mixed>|null null when the customer cannot be identified
     */
    public function identify(Customer $customer, array $consents = [], ?\DateTimeInterface $at = null): ?array
    {
        if (!$customer->identifiable()) {
            return null;
        }

        return array_filter(['type' => 'identify'] + $customer->ids() + [
            'traits' => (object) $customer->traits(),
            'consents' => $consents !== [] ? array_values($consents) : null,
            'timestamp' => $at !== null ? self::time($at) : null,
        ], static function ($value): bool {
            return $value !== null;
        });
    }

    /**
     * The order is placed. Products, totals and the customer are taken from the order.
     *
     * @return array<string, mixed>
     */
    public function orderCompleted(Order $order): array
    {
        return $this->order($order, self::ORDER_COMPLETED, $order->properties(), $order->placedAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function orderPaid(Order $order, ?\DateTimeInterface $at = null): array
    {
        return $this->order($order, self::ORDER_PAID, $order->properties(), $at ?? $order->placedAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function orderCancelled(Order $order, ?\DateTimeInterface $at = null): array
    {
        return $this->order($order, self::ORDER_CANCELLED, $order->properties(), $at ?? $order->placedAt);
    }

    /**
     * A full or partial refund: amount is the refunded sum, $refundId tells partial refunds apart.
     *
     * @param string|int|null $refundId
     * @param list<LineItem> $lines refunded products, if known
     *
     * @return array<string, mixed>
     */
    public function orderRefunded(Order $order, float $amount, $refundId = null, ?\DateTimeInterface $at = null, array $lines = []): array
    {
        $properties = ['order_id' => $order->id, 'amount' => round(max(0.0, $amount), 4), 'currency' => $order->currency];

        if ($lines !== []) {
            $properties['products'] = array_map(static function (LineItem $line): array {
                return $line->toArray();
            }, $lines);
        }

        return $this->order($order, self::ORDER_REFUNDED, $properties, $at ?? $order->placedAt, $refundId !== null ? (string) $refundId : null);
    }

    /**
     * A product event from the server (when the plugin tracks the cart on the backend): product_viewed,
     * add_to_cart or remove_from_cart.
     *
     * @return array<string, mixed>
     */
    public function product(string $event, Customer $customer, LineItem $line, string $currency, ?string $cartUrl = null): array
    {
        if (!in_array($event, ['product_viewed', 'add_to_cart', 'remove_from_cart'], true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a product event.', $event));
        }

        $properties = $line->toArray() + ['currency' => strtoupper($currency)];

        if ($event === 'product_viewed') {
            unset($properties['quantity']);
        }

        if ($cartUrl !== null && $event === 'add_to_cart') {
            $properties['cart_url'] = $cartUrl;
        }

        return ['type' => 'track', 'event' => $event] + $customer->ids() + ['properties' => $properties];
    }

    /**
     * The checkout page is opened with this cart.
     *
     * @param array<LineItem> $lines
     *
     * @return array<string, mixed>
     */
    public function checkoutStarted(Customer $customer, array $lines, float $amount, string $currency, ?string $cartUrl = null): array
    {
        $properties = [
            'amount' => round(max(0.0, $amount), 4),
            'currency' => strtoupper($currency),
            'products' => array_map(static function (LineItem $line): array {
                return $line->toArray();
            }, array_values($lines)),
        ];

        if ($cartUrl !== null) {
            $properties['cart_url'] = $cartUrl;
        }

        return ['type' => 'track', 'event' => 'checkout_started'] + $customer->ids() + ['properties' => $properties];
    }

    /**
     * A results page of the product search was shown (sent by whoever renders the results: the shop server or
     * cdp.search() of the tracker, once per query). Popular queries of the search suggestions come from these events.
     *
     * @return array<string, mixed>|null null for an empty query
     */
    public function search(Customer $customer, string $query, int $results, ?string $requestId = null): ?array
    {
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));

        if ($query === '') {
            return null;
        }

        $properties = ['query' => function_exists('mb_substr') ? mb_substr($query, 0, 200) : substr($query, 0, 200), 'results' => max(0, $results)];

        if ($requestId !== null && $requestId !== '') {
            $properties['request_id'] = $requestId;
        }

        return ['type' => 'track', 'event' => 'search'] + $customer->ids() + ['properties' => $properties];
    }

    /**
     * Deterministic id of an order event, at most 100 characters.
     */
    public function orderMessageId(string $orderId, string $event, ?string $suffix = null): string
    {
        $id = implode(':', array_filter([$this->platform, $this->shop, $orderId, $event, $suffix], static function ($part): bool {
            return $part !== null && $part !== '';
        }));

        return strlen($id) <= 100 ? $id : substr($id, 0, 59) . ':' . sha1($id);
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private function order(Order $order, string $event, array $properties, \DateTimeInterface $at, ?string $suffix = null): array
    {
        return [
            'type' => 'track',
            'messageId' => $this->orderMessageId($order->id, $event, $suffix),
            'event' => $event,
        ] + $order->customer->ids() + [
            'properties' => $properties,
            'timestamp' => self::time($at),
        ];
    }

    /**
     * @return array{channel: string, status: string, topic?: string}
     */
    private static function consent(string $channel, string $status, ?string $topic): array
    {
        $consent = ['channel' => $channel, 'status' => $status];

        if ($topic !== null && $topic !== '') {
            $consent['topic'] = $topic;
        }

        return $consent;
    }

    private static function time(\DateTimeInterface $at): string
    {
        return (new \DateTimeImmutable($at->format('Y-m-d\TH:i:s.uP')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
