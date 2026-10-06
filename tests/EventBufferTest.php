<?php

declare(strict_types=1);

namespace OneTrace\Tests;

use OneTrace\Exception\ServerException;
use OneTrace\Tests\Support\ClientFactory;
use OneTrace\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class EventBufferTest extends TestCase
{
    use ClientFactory;

    public function testSendsABatchWhenFlushAtIsReached(): void
    {
        $transport = (new FakeTransport())->push(202, ['accepted' => 3, 'duplicates' => 0]);
        $buffer = $this->client($transport)->events()->buffer(3);

        $buffer->track(['userId' => '42', 'event' => 'a']);
        $buffer->identify(['userId' => '42', 'traits' => ['plan' => 'pro']]);
        self::assertCount(2, $buffer);
        self::assertSame([], $transport->requests);

        $buffer->page(['anonymousId' => 'a-1']);

        self::assertCount(0, $buffer);
        self::assertCount(1, $transport->requests);
        self::assertSame(['track', 'identify', 'page'], array_column($transport->lastJson()['batch'], 'type'));
    }

    public function testFlushesTheRestWhenDestroyed(): void
    {
        $transport = new FakeTransport();
        $buffer = $this->client($transport)->events()->buffer();
        $buffer->alias(['previousId' => 'a-1', 'userId' => '42']);

        unset($buffer);

        self::assertCount(1, $transport->requests);
        self::assertSame('https://cdp.example.com/api/v1/batch', $transport->last()->getUrl());
    }

    public function testAFailedFlushKeepsMessagesWithTheSameIds(): void
    {
        $transport = (new FakeTransport())->push(500)->push(202, ['accepted' => 1, 'duplicates' => 0]);
        $buffer = $this->client($transport, ['max_retries' => 0])->events()->buffer();
        $buffer->track(['userId' => '42', 'event' => 'a']);

        try {
            $buffer->flush();
            self::fail('The flush should fail.');
        } catch (ServerException $e) {
            self::assertCount(1, $buffer);
        }

        $first = json_decode((string) $transport->requests[0]->getBody(), true)['batch'][0]['messageId'];
        self::assertSame(['accepted' => 1, 'duplicates' => 0], $buffer->flush());
        self::assertSame($first, $transport->lastJson()['batch'][0]['messageId']);
    }

    public function testDestructorErrorsGoToTheCallback(): void
    {
        $transport = (new FakeTransport())->push(500);
        $errors = [];
        $buffer = $this->client($transport, ['max_retries' => 0])->events()->buffer(100, static function (\Throwable $error, array $lost) use (&$errors): void {
            $errors[] = [\get_class($error), \count($lost)];
        });
        $buffer->track(['userId' => '42', 'event' => 'a']);

        unset($buffer);

        self::assertSame([[ServerException::class, 1]], $errors);
    }
}
