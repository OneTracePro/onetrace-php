<?php

declare(strict_types=1);

namespace OneTrace\Tests;

use OneTrace\Tests\Support\ClientFactory;
use OneTrace\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    use ClientFactory;

    public function testListReturnsAPage(): void
    {
        $transport = (new FakeTransport())->push(200, ['data' => [['id' => 1], ['id' => 2]], 'next_cursor' => 'abc']);

        $page = $this->client($transport)->segments()->list(['limit' => 2]);

        self::assertCount(2, $page);
        self::assertSame([['id' => 1], ['id' => 2]], $page->getData());
        self::assertSame('abc', $page->getNextCursor());
        self::assertTrue($page->hasMore());
        self::assertSame([1, 2], array_column(iterator_to_array($page), 'id'));
    }

    public function testIterateWalksAllPages(): void
    {
        $transport = (new FakeTransport())
            ->push(200, ['data' => [['id' => 1], ['id' => 2]], 'next_cursor' => 'c2'])
            ->push(200, ['data' => [['id' => 3]], 'next_cursor' => null]);

        $ids = [];

        foreach ($this->client($transport)->segments()->iterateMembers(5, ['limit' => 2]) as $member) {
            $ids[] = $member['id'];
        }

        self::assertSame([1, 2, 3], $ids);
        self::assertSame('https://cdp.example.com/api/v1/segments/5/members?limit=2', $transport->requests[0]->getUrl());
        self::assertSame('https://cdp.example.com/api/v1/segments/5/members?limit=2&cursor=c2', $transport->requests[1]->getUrl());
    }

    public function testProfileEventsUseTheBeforeCursor(): void
    {
        $transport = (new FakeTransport())
            ->push(200, ['data' => [['event' => 'a']], 'next_before' => '2026-10-01T10:00:00+00:00'])
            ->push(200, ['data' => [['event' => 'b']], 'next_before' => null]);

        $events = iterator_to_array($this->client($transport)->profiles()->iterateEvents('user_id', '42', ['name' => 'a']), false);

        self::assertSame(['a', 'b'], array_column($events, 'event'));
        self::assertSame('https://cdp.example.com/api/v1/profiles/user_id/42/events?name=a&before=2026-10-01T10%3A00%3A00%2B00%3A00', $transport->requests[1]->getUrl());
    }

    public function testIterationIsLazy(): void
    {
        $transport = (new FakeTransport())->push(200, ['data' => [['id' => 1]], 'next_cursor' => 'c2']);

        foreach ($this->client($transport)->journeys()->iterate() as $journey) {
            break;
        }

        self::assertCount(1, $transport->requests);
    }
}
