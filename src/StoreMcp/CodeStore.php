<?php

declare(strict_types=1);

namespace OneTrace\StoreMcp;

/**
 * Short-lived storage for confirmation codes and their limits: the store's cache (WordPress transients, Laravel
 * cache, Magento cache, Bitrix cache …).
 */
interface CodeStore
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array;

    /**
     * @param array<string, mixed> $value
     */
    public function put(string $key, array $value, int $ttl): void;

    public function delete(string $key): void;
}
