<?php

declare(strict_types=1);

namespace OneTrace;

/**
 * One page of a list. Iterate it for the items on this page; pass getNextCursor() as "cursor"
 * (or "before" for profile events) to get the next one, or use the resource's iterate…() helpers.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class Page implements \IteratorAggregate, \Countable
{
    /** @var list<array<string, mixed>> */
    private array $data;

    private ?string $nextCursor;

    /**
     * @param list<array<string, mixed>> $data
     */
    public function __construct(array $data, ?string $nextCursor)
    {
        $this->data = $data;
        $this->nextCursor = $nextCursor;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @internal
     */
    public static function fromResponse(array $response, string $cursorField = 'next_cursor'): self
    {
        $data = isset($response['data']) && \is_array($response['data']) ? array_values($response['data']) : [];
        $cursor = $response[$cursorField] ?? null;

        /** @var list<array<string, mixed>> $data */
        return new self($data, \is_scalar($cursor) && (string) $cursor !== '' ? (string) $cursor : null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getNextCursor(): ?string
    {
        return $this->nextCursor;
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }

    /**
     * @return \ArrayIterator<int, array<string, mixed>>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function count(): int
    {
        return \count($this->data);
    }
}
