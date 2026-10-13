<?php

declare(strict_types=1);

namespace OneTrace\Resource;

/**
 * Promo code pools: a shared code or unique codes issued to profiles. The store applies the discount with its own
 * coupon rule; the platform issues the codes and counts their use from the coupon of order events. Reading needs a
 * secret key with promo.read, changes need promo.write.
 *
 * Store plugins keep the coupons of a pool in sync with codes(): pass the returned cursor next time.
 */
final class Promo extends Resource
{
    /**
     * Pools with statistics: codes by status, orders with codes and their revenue.
     *
     * @return array<string, mixed> data
     */
    public function pools(): array
    {
        return $this->requester->request('GET', '/promo/pools');
    }

    /**
     * @return array<string, mixed>
     */
    public function pool(string $key): array
    {
        return $this->requester->request('GET', $this->path('/promo/pools/{key}', ['key' => $key]));
    }

    /**
     * Codes of the pool created or changed after the cursor, oldest first. Store `next` and pass it as $since next
     * time; an empty page returns the same cursor.
     *
     * @return array<string, mixed> data, next
     */
    public function codes(string $key, ?string $since = null, int $limit = 500): array
    {
        return $this->requester->request('GET', $this->path('/promo/pools/{key}/codes', ['key' => $key]), [
            'query' => self::filled(['since' => $since, 'limit' => $limit]),
        ]);
    }

    /**
     * All codes changed after the cursor across pages; the generator returns the last cursor.
     *
     * @return \Generator<int, array<mixed>, mixed, string|null>
     */
    public function iterateCodes(string $key, ?string $since = null, int $limit = 500): \Generator
    {
        do {
            $page = $this->codes($key, $since, $limit);
            $codes = isset($page['data']) && \is_array($page['data']) ? $page['data'] : [];

            foreach ($codes as $code) {
                if (\is_array($code)) {
                    yield $code;
                }
            }

            $since = isset($page['next']) && \is_string($page['next']) ? $page['next'] : $since;
        } while (\count($codes) >= $limit);

        return $since;
    }

    /**
     * A code by value (case-insensitive): pool, status, expiry, active, uses.
     *
     * @return array<string, mixed>
     */
    public function check(string $code): array
    {
        return $this->requester->request('GET', $this->path('/promo/codes/{code}', ['code' => $code]));
    }

    /**
     * Codes issued to the profile, newest first.
     *
     * @return array<string, mixed> data
     */
    public function profileCodes(string $type, string $value): array
    {
        return $this->requester->request('GET', $this->path('/promo/profiles/{type}/{value}/codes', $this->identity($type, $value)));
    }

    /**
     * Generates $count codes with the prefix and length of the pool.
     *
     * @return array<string, mixed> added, skipped
     */
    public function generate(string $key, int $count, ?string $idempotencyKey = null): array
    {
        return $this->add($key, ['count' => $count], $idempotencyKey);
    }

    /**
     * Uploads ready codes (e.g. coupons exported from the store); codes already in the project are skipped.
     *
     * @param list<string> $codes
     *
     * @return array<string, mixed> added, skipped
     */
    public function import(string $key, array $codes, ?string $idempotencyKey = null): array
    {
        return $this->add($key, ['codes' => $codes], $idempotencyKey);
    }

    /**
     * Issues a code of the pool to the profile; a profile with an active code of the pool gets the same code.
     * Throws ApiException 409 with reason inactive, limit or exhausted when no code can be issued.
     *
     * @param array{type: string, value: string} $profile
     *
     * @return array<string, mixed> pool, code, status, issued_at, expires_at
     */
    public function issue(string $key, array $profile, ?string $idempotencyKey = null): array
    {
        return $this->requester->request('POST', $this->path('/promo/pools/{key}/issue', ['key' => $key]), [
            'json' => ['profile' => $profile],
            'idempotent' => true,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function add(string $key, array $body, ?string $idempotencyKey): array
    {
        return $this->requester->request('POST', $this->path('/promo/pools/{key}/codes', ['key' => $key]), [
            'json' => $body,
            'idempotent' => true,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
