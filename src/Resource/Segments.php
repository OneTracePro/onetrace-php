<?php

declare(strict_types=1);

namespace OneTrace\Resource;

use OneTrace\Page;

/**
 * Segments and their members. Needs a secret key with segments.read or segments.write.
 */
final class Segments extends Resource
{
    /**
     * @param array<string, mixed> $params limit (1–200, 50 by default), cursor
     */
    public function list(array $params = []): Page
    {
        return Page::fromResponse($this->requester->request('GET', '/segments', ['query' => $params]));
    }

    /**
     * All segments across pages.
     *
     * @param array<string, mixed> $params limit
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): \Generator
    {
        return $this->paginate(function (?string $cursor) use ($params): Page {
            return $this->list(self::filled($params + ['cursor' => $cursor]));
        });
    }

    /**
     * Creates a segment: create(['name' => 'VIP', 'type' => 'static']) or a dynamic one with "rules".
     *
     * @param array<string, mixed> $segment         name, type (dynamic, static), description, realtime, schedule_minutes, rules
     * @param string|null          $idempotencyKey  a repeat with the same key returns the same segment; generated if null
     *
     * @return array<string, mixed>
     */
    public function create(array $segment, ?string $idempotencyKey = null): array
    {
        return $this->requester->request('POST', '/segments', ['json' => $segment, 'idempotent' => true, 'idempotency_key' => $idempotencyKey]);
    }

    /**
     * The segment with its rules.
     *
     * @return array<string, mixed>
     */
    public function get(int $segment): array
    {
        return $this->requester->request('GET', $this->path('/segments/{id}', ['id' => $segment]));
    }

    /**
     * Partial update: name, description, realtime, schedule_minutes, rules.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public function update(int $segment, array $changes): array
    {
        return $this->requester->request('PATCH', $this->path('/segments/{id}', ['id' => $segment]), ['json' => $changes]);
    }

    public function delete(int $segment): void
    {
        $this->requester->request('DELETE', $this->path('/segments/{id}', ['id' => $segment]));
    }

    /**
     * Queues a recomputation of a dynamic segment.
     *
     * @return array<string, mixed> queued
     */
    public function compute(int $segment): array
    {
        return $this->requester->request('POST', $this->path('/segments/{id}/compute', ['id' => $segment]));
    }

    /**
     * Members, latest entered first.
     *
     * @param array<string, mixed> $params limit (1–200, 50 by default), cursor
     */
    public function members(int $segment, array $params = []): Page
    {
        return Page::fromResponse($this->requester->request('GET', $this->path('/segments/{id}/members', ['id' => $segment]), ['query' => $params]));
    }

    /**
     * All members across pages.
     *
     * @param array<string, mixed> $params limit
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateMembers(int $segment, array $params = []): \Generator
    {
        return $this->paginate(function (?string $cursor) use ($segment, $params): Page {
            return $this->members($segment, self::filled($params + ['cursor' => $cursor]));
        });
    }

    /**
     * Adds profiles to a static segment: addMembers(12, [Identity::email('anna@example.com')]). Up to 1000 per call.
     *
     * @param array<array{type: string, value: string}> $identities
     *
     * @return array<string, mixed> added, unknown
     */
    public function addMembers(int $segment, array $identities, ?string $idempotencyKey = null): array
    {
        return $this->requester->request('POST', $this->path('/segments/{id}/members', ['id' => $segment]), [
            'json' => ['identities' => array_values($identities)],
            'idempotent' => true,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * Removes profiles from a static segment. Up to 1000 per call.
     *
     * @param array<array{type: string, value: string}> $identities
     *
     * @return array<string, mixed> removed, unknown
     */
    public function removeMembers(int $segment, array $identities): array
    {
        return $this->requester->request('DELETE', $this->path('/segments/{id}/members', ['id' => $segment]), [
            'json' => ['identities' => array_values($identities)],
        ]);
    }

    /**
     * Whether a profile is a member: membership(12, 'email', 'anna@example.com').
     *
     * @return array<string, mixed> profile_id, member, entered_at
     */
    public function membership(int $segment, string $type, string $value): array
    {
        $identity = $this->identity($type, $value);

        return $this->requester->request('GET', $this->path('/segments/{id}/members/{type}/{value}', ['id' => $segment] + $identity));
    }
}
