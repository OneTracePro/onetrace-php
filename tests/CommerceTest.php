<?php

declare(strict_types=1);

namespace OneTrace\Tests;

use JsonSchema\Validator;
use OneTrace\Commerce\CatalogItem;
use OneTrace\Commerce\Customer;
use OneTrace\Commerce\LineItem;
use OneTrace\Commerce\Messages;
use OneTrace\Commerce\Order;
use OneTrace\Commerce\Retry;
use OneTrace\Exception\ApiException;
use OneTrace\Exception\TransportException;
use OneTrace\Http\Response;
use PHPUnit\Framework\TestCase;

final class CommerceTest extends TestCase
{
    private function order(?Customer $customer = null): Order
    {
        $customer = $customer ?? (new Customer('1042', 'anon-1'))->email(' Anna@Example.com ')->phone('+49 151 1234-5678')->name('Anna', '')->country('de')->language('pt_br');

        return (new Order('A-1001', 109.9, 'eur', new \DateTimeImmutable('2026-10-08 12:30:00.250', new \DateTimeZone('Europe/Berlin')), $customer, [
            new LineItem(17, 'Sneakers', 49.95, 2, 18, 5),
            new LineItem('SKU-2', '', 10.0),
        ]))->with(['subtotal' => 109.9, 'shipping' => 0, 'coupon' => ' AUTUMN ', 'payment_method' => '', 'unknown' => 1]);
    }

    /**
     * @param array<string, mixed>|null $message
     */
    private static function assertMatchesContract(?array $message): void
    {
        self::assertNotNull($message);
        $data = json_decode((string) json_encode($message));
        $validator = new Validator();
        $validator->validate($data, (object) ['$ref' => 'file://' . realpath(__DIR__ . '/../resources/ecommerce-events.schema.json')]);

        self::assertTrue($validator->isValid(), json_encode($validator->getErrors(), JSON_PRETTY_PRINT) ?: '');
    }

    public function testBuildsOrderEventsWithDeterministicIdsAndUtcTime(): void
    {
        $messages = new Messages('WooCommerce', 'https://shop.example.com/');
        $completed = $messages->orderCompleted($this->order());
        $refund = $messages->orderRefunded($this->order(), 20, 7);

        self::assertSame('track', $completed['type']);
        self::assertSame('order_completed', $completed['event']);
        self::assertSame(['userId' => '1042', 'anonymousId' => 'anon-1'], array_intersect_key($completed, ['userId' => 1, 'anonymousId' => 1]));
        self::assertSame('2026-10-08T10:30:00.250Z', $completed['timestamp']);
        self::assertSame(['order_id' => 'A-1001', 'amount' => 109.9, 'currency' => 'EUR', 'subtotal' => 109.9, 'shipping' => 0.0, 'coupon' => 'AUTUMN'], array_diff_key($completed['properties'], ['products' => 1]));
        self::assertSame([
            ['product_id' => '17', 'variant_id' => '18', 'product_name' => 'Sneakers', 'price' => 49.95, 'quantity' => 2, 'category_id' => '5'],
            ['product_id' => 'SKU-2', 'product_name' => 'SKU-2', 'price' => 10.0, 'quantity' => 1],
        ], $completed['properties']['products']);
        self::assertSame($completed['messageId'], $messages->orderCompleted($this->order())['messageId']);
        self::assertStringStartsWith('woocommerce:', $completed['messageId']);
        self::assertStringEndsWith(':A-1001:order_refunded:7', $refund['messageId']);
        self::assertNotSame($completed['messageId'], (new Messages('woocommerce', 'https://other.example.com'))->orderCompleted($this->order())['messageId']);
        self::assertSame(20.0, $refund['properties']['amount']);

        foreach ([$completed, $refund, $messages->orderPaid($this->order()), $messages->orderCancelled($this->order())] as $message) {
            self::assertMatchesContract($message);
        }
    }

    public function testSendsConsentsWithTheSecretKeyEvenWhenTheWriteKeyIsSet(): void
    {
        $transport = new Support\FakeTransport();
        $transport->push(202, ['accepted' => 1, 'duplicates' => 0]);
        $transport->push(202, ['accepted' => 1, 'duplicates' => 0]);
        $client = new \OneTrace\Client('https://cdp.example.com', ['write_key' => 'cdp_wk_test', 'secret_key' => 'cdp_sk_test', 'transport' => $transport]);
        $customer = (new Customer('1'))->email('anna@example.com');
        $messages = new Messages('shop', 'https://shop.example.com');

        $client->events()->batch([$messages->identify($customer, [Messages::subscribed('email')])]);
        $client->events()->batch([$messages->identify($customer)]);

        self::assertSame(['Bearer cdp_sk_test', 'Bearer cdp_wk_test'], array_map(static function ($request): string {
            return $request->getHeaders()['Authorization'];
        }, $transport->requests));
    }

    public function testKeepsLongMessageIdsWithinTheLimit(): void
    {
        $id = (new Messages('woocommerce', 'https://shop.example.com'))->orderMessageId(str_repeat('9', 120), 'order_paid');

        self::assertLessThanOrEqual(100, strlen($id));
        self::assertNotSame($id, (new Messages('woocommerce', 'https://shop.example.com'))->orderMessageId(str_repeat('9', 121), 'order_paid'));
    }

    public function testIdentifiesCustomersWithNormalizedTraitsAndConsents(): void
    {
        $messages = new Messages('woocommerce', 'https://shop.example.com');
        $identify = $messages->identify($this->order()->customer, [Messages::subscribed('email'), Messages::unsubscribed('sms', 'promo')]);

        self::assertSame(['email' => 'anna@example.com', 'phone' => '+4915112345678', 'first_name' => 'Anna', 'country' => 'DE', 'language' => 'pt-BR'], (array) $identify['traits']);
        self::assertSame([['channel' => 'email', 'status' => 'subscribed'], ['channel' => 'sms', 'status' => 'unsubscribed', 'topic' => 'promo']], $identify['consents']);
        self::assertMatchesContract($identify);

        // Гость: только email; национальный номер без кода страны отбрасывается.
        $guest = $messages->identify((new Customer())->email('guest@example.com')->phone('8 (900) 123-45-67')->phone('0049 30 123456'));
        self::assertSame(['email' => 'guest@example.com', 'phone' => '+4930123456'], (array) $guest['traits']);
        self::assertArrayNotHasKey('consents', $guest);
        self::assertMatchesContract($guest);

        self::assertNull($messages->identify((new Customer(null, 'anon'))->email('not-an-email')));

        // Гость без cookie трекера: identify и заказ получают одинаковый anonymousId из email.
        $order = new Order('A-7', 10, 'EUR', new \DateTimeImmutable(), (new Customer())->email('Guest@Example.com'));
        self::assertStringStartsWith('guest:', $guest['anonymousId']);
        self::assertSame($messages->identify($order->customer)['anonymousId'], $messages->orderCompleted($order)['anonymousId']);
    }

    public function testBuildsProductAndCheckoutEvents(): void
    {
        $messages = new Messages('woocommerce', 'https://shop.example.com');
        $customer = new Customer(null, 'anon-1');
        $line = new LineItem(17, 'Sneakers', 49.95, 2, null, 5);

        $viewed = $messages->product('product_viewed', $customer, $line, 'eur');
        $added = $messages->product('add_to_cart', $customer, $line, 'EUR', 'https://shop.example.com/cart');
        $checkout = $messages->checkoutStarted($customer, [$line], 99.9, 'EUR', 'https://shop.example.com/cart');

        self::assertArrayNotHasKey('quantity', $viewed['properties']);
        self::assertSame('https://shop.example.com/cart', $added['properties']['cart_url']);

        foreach ([$viewed, $added, $messages->product('remove_from_cart', $customer, $line, 'EUR'), $checkout] as $message) {
            self::assertMatchesContract($message);
        }

        $this->expectException(\InvalidArgumentException::class);
        $messages->product('order_completed', $customer, $line, 'EUR');
    }

    public function testBuildsCatalogItems(): void
    {
        $item = CatalogItem::make(17, 'Sneakers', 'https://shop.example.com/p/17', 'not a url', 39.9, 'eur', true, [5, 1], 49.9, ' Nike ', ['Color' => 'white', 'Empty' => '', 'List' => null] + array_fill_keys(range(1, 30), 'x'));

        self::assertSame(['id' => '17', 'name' => 'Sneakers', 'url' => 'https://shop.example.com/p/17', 'price' => 39.9, 'old_price' => 49.9, 'currency' => 'EUR', 'available' => true, 'category_ids' => ['5', '1'], 'brand' => 'Nike'], array_diff_key($item, ['params' => 1]));
        self::assertCount(CatalogItem::MAX_PARAMS, $item['params']);
        self::assertSame('white', $item['params']['Color']);
        self::assertArrayNotHasKey('old_price', CatalogItem::make(1, 'X', null, null, 50.0, 'EUR', false, [], 40.0));
        self::assertSame(['id' => '5', 'name' => 'Shoes'], CatalogItem::category(5, 'Shoes', 0));
        self::assertSame(['id' => '6', 'name' => 'Boots', 'parent_id' => '5'], CatalogItem::category(6, 'Boots', 5));
    }

    public function testDecidesWhatToDoWithFailedBatches(): void
    {
        self::assertSame(Retry::RETRY, Retry::decide(new TransportException('timeout')));
        self::assertSame(Retry::RETRY, Retry::decide(ApiException::fromResponse(new Response(429, [], '{"message":"slow down"}'))));
        self::assertSame(Retry::RETRY, Retry::decide(ApiException::fromResponse(new Response(503, [], '{}'))));
        self::assertSame(Retry::DROP, Retry::decide(ApiException::fromResponse(new Response(422, [], '{"message":"bad","errors":{}}'))));
        self::assertSame(Retry::SETTINGS, Retry::decide(ApiException::fromResponse(new Response(401, [], '{}'))));
        self::assertSame(Retry::SETTINGS, Retry::decide(ApiException::fromResponse(new Response(403, [], '{}'))));
        self::assertSame(60, Retry::delay(1));
        self::assertNull(Retry::delay(6));
    }
}
