<?php

declare(strict_types=1);

namespace OneTrace\Tests;

use OneTrace\Identity;
use OneTrace\Tests\Support\ClientFactory;
use OneTrace\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ChatsTest extends TestCase
{
    use ClientFactory;

    public function testFiltersConversationsByCustomerAndWalksAllPages(): void
    {
        $transport = (new FakeTransport())
            ->push(200, ['data' => [['id' => 'c-1']], 'next_cursor' => 'n1'])
            ->push(200, ['data' => [['id' => 'c-2']], 'next_cursor' => null]);

        $ids = [];

        foreach ($this->client($transport)->chats()->iterateConversations(['status' => 'open', 'profile' => Identity::email('anna@example.com')]) as $conversation) {
            $ids[] = $conversation['id'];
        }

        self::assertSame(['c-1', 'c-2'], $ids);
        self::assertSame('https://cdp.example.com/api/v1/conversations?status=open&profile_type=email&profile_value=anna%40example.com', $transport->requests[0]->getUrl());
        self::assertStringContainsString('cursor=n1', $transport->last()->getUrl());
    }

    public function testAnswersOnceForTheSameIdempotencyKey(): void
    {
        $transport = (new FakeTransport())->push(201, ['id' => 7, 'via' => 'api']);

        $this->client($transport)->chats()->reply('0199c0de-0000-7000-8000-000000000003', 'Your order ships today.', 'ticket-42');

        self::assertSame(['body' => 'Your order ships today.'], $transport->lastJson());
        self::assertSame('ticket-42', $transport->last()->getHeader('Idempotency-Key'));
    }
}
