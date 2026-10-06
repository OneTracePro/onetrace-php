<?php

declare(strict_types=1);

namespace OneTrace\Tests;

use OneTrace\Client;
use OneTrace\Resource\Events;
use OneTrace\Tests\Support\ClientFactory;
use OneTrace\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class EventsTest extends TestCase
{
    use ClientFactory;

    public function testTrackFillsMessageIdTimestampAndLibrary(): void
    {
        $transport = (new FakeTransport())->push(202, ['accepted' => 1, 'duplicates' => 0]);

        $result = $this->client($transport)->events()->track(['userId' => 42, 'event' => 'order_completed', 'properties' => ['amount' => 9980.0]]);

        self::assertSame(['accepted' => 1, 'duplicates' => 0], $result);
        self::assertSame('https://cdp.example.com/api/v1/track', $transport->last()->getUrl());
        $body = $transport->lastJson();
        self::assertSame('track', $body['type']);
        self::assertSame('42', $body['userId']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $body['messageId']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $body['timestamp']);
        self::assertSame(['name' => 'onetrace-php', 'version' => Client::VERSION], $body['context']['library']);
        self::assertStringContainsString('"amount":9980.0', (string) $transport->last()->getBody());
    }

    public function testKeepsGivenMessageIdTimestampAndContext(): void
    {
        $transport = (new FakeTransport())->push(202);
        $at = new \DateTimeImmutable('2026-10-01 10:00:00', new \DateTimeZone('Europe/Nicosia'));

        $this->client($transport)->events()->identify([
            'userId' => '42',
            'messageId' => 'order-A-1001',
            'timestamp' => $at,
            'context' => ['ip' => '203.0.113.5'],
            'traits' => [],
        ]);

        $body = $transport->lastJson();
        self::assertSame('order-A-1001', $body['messageId']);
        self::assertSame('2026-10-01T10:00:00.000+03:00', $body['timestamp']);
        self::assertSame('203.0.113.5', $body['context']['ip']);
        self::assertStringContainsString('"traits":{}', (string) $transport->last()->getBody(), 'Empty traits must be a JSON object.');
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function invalidMessages(): array
    {
        return [
            'track without ids' => ['track', ['event' => 'x'], 'userId'],
            'track without event' => ['track', ['userId' => '42'], 'event'],
            'identify with empty ids' => ['identify', ['userId' => ' ', 'anonymousId' => ''], 'userId'],
            'alias without previousId' => ['alias', ['userId' => '42'], 'previousId'],
        ];
    }

    /**
     * @dataProvider invalidMessages
     *
     * @param array<string, mixed> $message
     */
    public function testRejectsInvalidMessagesBeforeSending(string $type, array $message, string $mention): void
    {
        $transport = new FakeTransport();

        try {
            $this->client($transport)->events()->{$type}($message);
            self::fail('An exception was expected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($mention, $e->getMessage());
        }

        self::assertSame([], $transport->requests);
    }

    public function testBatchSplitsIntoRequestsOf500AndSumsCounters(): void
    {
        $transport = (new FakeTransport())
            ->push(202, ['accepted' => 500, 'duplicates' => 0])
            ->push(202, ['accepted' => 98, 'duplicates' => 2]);
        $messages = [];

        for ($i = 0; $i < 600; $i++) {
            $messages[] = ['type' => 'track', 'anonymousId' => 'a-' . $i, 'event' => 'product_viewed'];
        }

        $result = $this->client($transport)->events()->batch($messages);

        self::assertSame(['accepted' => 598, 'duplicates' => 2], $result);
        self::assertCount(2, $transport->requests);
        self::assertSame('https://cdp.example.com/api/v1/batch', $transport->requests[0]->getUrl());
        self::assertCount(500, json_decode((string) $transport->requests[0]->getBody(), true)['batch']);
        self::assertCount(100, json_decode((string) $transport->requests[1]->getBody(), true)['batch']);
    }

    public function testBatchSplitsByBodySize(): void
    {
        $transport = new FakeTransport();
        $large = str_repeat('x', 300000);
        $messages = [];

        for ($i = 0; $i < 5; $i++) {
            $messages[] = ['type' => 'track', 'userId' => '42', 'event' => 'note', 'properties' => ['text' => $large]];
        }

        $this->client($transport)->events()->batch($messages);

        self::assertCount(3, $transport->requests);

        foreach ($transport->requests as $request) {
            self::assertLessThan(1000000, \strlen((string) $request->getBody()));
        }
    }

    public function testBatchNeedsATypeForEachMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Message 1 needs "type"');

        $this->client(new FakeTransport())->events()->batch([
            ['type' => 'track', 'userId' => '42', 'event' => 'x'],
            ['userId' => '42', 'event' => 'y'],
        ]);
    }

    public function testRejectsAMessageOverTheBatchLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->client(new FakeTransport())->events()->batch([
            ['type' => 'track', 'userId' => '42', 'event' => 'x', 'properties' => ['text' => str_repeat('x', Events::MAX_BATCH_BYTES)]],
        ]);
    }
}
