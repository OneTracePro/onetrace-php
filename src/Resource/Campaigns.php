<?php

declare(strict_types=1);

namespace OneTrace\Resource;

use OneTrace\Page;

/**
 * Campaigns (one-off and recurring sends). Needs a secret key with campaigns.read, campaigns.write or campaigns.send.
 */
final class Campaigns extends Resource
{
    /**
     * @param array<string, mixed> $params limit (1–200, 50 by default), cursor
     */
    public function list(array $params = []): Page
    {
        return Page::fromResponse($this->requester->request('GET', '/campaigns', ['query' => $params]));
    }

    /**
     * All campaigns across pages.
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
     * Creates a draft: name, audience, variants and schedule are required; description, integration_id, ab,
     * rate_per_minute and goal are optional.
     *
     * @param array<string, mixed> $campaign
     *
     * @return array<string, mixed>
     */
    public function create(array $campaign, ?string $idempotencyKey = null): array
    {
        return $this->requester->request('POST', '/campaigns', ['json' => $campaign, 'idempotent' => true, 'idempotency_key' => $idempotencyKey]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $campaign): array
    {
        return $this->requester->request('GET', $this->path('/campaigns/{id}', ['id' => $campaign]));
    }

    /**
     * Partial update of a draft or a scheduled campaign.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public function update(int $campaign, array $changes): array
    {
        return $this->requester->request('PATCH', $this->path('/campaigns/{id}', ['id' => $campaign]), ['json' => $changes]);
    }

    public function delete(int $campaign): void
    {
        $this->requester->request('DELETE', $this->path('/campaigns/{id}', ['id' => $campaign]));
    }

    /**
     * Last runs with per-variant stats and the A/B verdict.
     *
     * @return array<string, mixed> campaign, runs
     */
    public function report(int $campaign): array
    {
        return $this->requester->request('GET', $this->path('/campaigns/{id}/report', ['id' => $campaign]));
    }

    /**
     * Schedules the campaign; with schedule type "now" it starts right away.
     *
     * @return array<string, mixed>
     */
    public function schedule(int $campaign, ?string $idempotencyKey = null): array
    {
        return $this->action($campaign, 'schedule', $idempotencyKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function pause(int $campaign, ?string $idempotencyKey = null): array
    {
        return $this->action($campaign, 'pause', $idempotencyKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function resume(int $campaign, ?string $idempotencyKey = null): array
    {
        return $this->action($campaign, 'resume', $idempotencyKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(int $campaign, ?string $idempotencyKey = null): array
    {
        return $this->action($campaign, 'cancel', $idempotencyKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function action(int $campaign, string $action, ?string $idempotencyKey): array
    {
        return $this->requester->request('POST', $this->path('/campaigns/{id}/' . $action, ['id' => $campaign]), [
            'idempotent' => true,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
