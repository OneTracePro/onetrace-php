<?php

declare(strict_types=1);

namespace OneTrace\Tests;

use OneTrace\Commerce\Customer;
use OneTrace\Commerce\LineItem;
use OneTrace\Commerce\Order;
use OneTrace\Resource\Emails;
use OneTrace\Tests\Support\ClientFactory;
use OneTrace\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class EmailsTest extends TestCase
{
    use ClientFactory;

    public function testSendsAStoreEmailWithAttachmentsOnce(): void
    {
        $transport = (new FakeTransport())->push(202, ['id' => 'm-1', 'kind' => 'order_placed', 'template' => false]);

        $this->client($transport)->emails()->send([
            'to' => 'anna@example.com',
            'kind' => 'order_placed',
            'subject' => 'Order 1001',
            'html' => '<p>Thanks!</p>',
            'text' => null,
            'attachments' => [Emails::attachment('invoice-1001.pdf', 'application/pdf', '%PDF-1.4')],
        ], 'wc-1001-placed');

        self::assertSame([
            'to' => 'anna@example.com',
            'kind' => 'order_placed',
            'subject' => 'Order 1001',
            'html' => '<p>Thanks!</p>',
            'attachments' => [['name' => 'invoice-1001.pdf', 'type' => 'application/pdf', 'content' => base64_encode('%PDF-1.4')]],
        ], $transport->lastJson());
        self::assertSame('wc-1001-placed', $transport->last()->getHeader('Idempotency-Key'));
        self::assertSame('https://cdp.example.com/api/v1/emails', $transport->last()->getUrl());
    }

    public function testRoutesEmailsBySettings(): void
    {
        $transport = (new FakeTransport())->push(200, ['relay' => true, 'integration_id' => null, 'templates' => ['order_shipped' => true, 'order_placed' => false]]);
        $settings = $this->client($transport)->emails()->settings();

        self::assertSame(Emails::TEMPLATE, Emails::route($settings, 'order_shipped'));
        self::assertSame(Emails::RELAY, Emails::route($settings, 'order_placed'));
        self::assertSame(Emails::RELAY, Emails::route($settings, 'password_reset'));
        self::assertSame(Emails::NATIVE, Emails::route(['relay' => false, 'templates' => []], 'password_reset'));
        // An order template works without the relay: the store does not render the email at all.
        self::assertSame(Emails::TEMPLATE, Emails::route(['relay' => false, 'templates' => ['order_shipped' => true]], 'order_shipped'));
    }

    public function testBuildsTheOrderOfAnOrderEmail(): void
    {
        $line = (new LineItem('SKU-1', 'Sneakers', 49.95, 2, 'SKU-1-42', 'shoes'))
            ->withDisplay(['sku' => 'SN-42', 'options' => 'Size: 42', 'image_url' => 'https://shop.example/1.jpg', 'url' => 'javascript:alert(1)']);
        $order = (new Order('A-1001', 109.90, 'eur', new \DateTimeImmutable('2026-10-19T10:00:00+00:00'), new Customer('42'), [$line]))
            ->with(['shipping' => 10, 'coupon' => 'AUTUMN']);

        $data = $order->emailData([
            'number' => '1001',
            'url' => 'https://shop.example/account/orders/1001',
            'tracking_url' => 'ftp://example.com/x',
            'tracking_number' => ' JD0001 ',
            'estimated_delivery' => new \DateTimeImmutable('2026-10-22'),
            'shipping_address' => ['name' => 'Anna Smith', 'city' => 'Berlin', 'phone' => ''],
            'billing_address' => ['name' => ''],
            'unknown' => 'dropped',
        ]);

        self::assertSame([
            'order_id' => 'A-1001',
            'number' => '1001',
            'created_at' => '2026-10-19T10:00:00+00:00',
            'currency' => 'EUR',
            'amount' => 109.9,
            'shipping' => 10.0,
            'coupon' => 'AUTUMN',
            'url' => 'https://shop.example/account/orders/1001',
            'tracking_number' => 'JD0001',
            'estimated_delivery' => '2026-10-22',
            'shipping_address' => ['name' => 'Anna Smith', 'city' => 'Berlin'],
            'products' => [[
                'product_id' => 'SKU-1',
                'variant_id' => 'SKU-1-42',
                'product_name' => 'Sneakers',
                'price' => 49.95,
                'quantity' => 2,
                'total' => 99.9,
                'sku' => 'SN-42',
                'options' => 'Size: 42',
                'image_url' => 'https://shop.example/1.jpg',
            ]],
        ], $data);
        // Events keep the contract: no display fields in order events.
        self::assertSame(['product_id' => 'SKU-1', 'variant_id' => 'SKU-1-42', 'product_name' => 'Sneakers', 'price' => 49.95, 'quantity' => 2, 'category_id' => 'shoes'], $line->toArray());
    }
}
