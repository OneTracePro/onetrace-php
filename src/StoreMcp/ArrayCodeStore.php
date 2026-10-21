<?php

declare(strict_types=1);

namespace OneTrace\StoreMcp;

/**
 * In-memory CodeStore for tests and single-process servers.
 */
final class ArrayCodeStore implements CodeStore
{
    /** @var array<string, array{value: array<string, mixed>, expires: int}> */
    private $items = [];

    public function get(string $key): ?array
    {
        $item = $this->items[$key] ?? null;

        return $item !== null && $item['expires'] >= time() ? $item['value'] : null;
    }

    public function put(string $key, array $value, int $ttl): void
    {
        $this->items[$key] = ['value' => $value, 'expires' => time() + $ttl];
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }
}
