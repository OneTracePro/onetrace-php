<?php

declare(strict_types=1);

namespace OneTrace\Resource;

use OneTrace\Page;

/**
 * Journeys: drafts, publishing, participants and the API trigger.
 * Needs a secret key with journeys.read, journeys.write or journeys.publish.
 */
final class Journeys extends Resource
{
    /**
     * @param array<string, mixed> $params limit (1–200, 50 by default), cursor, status (draft, active, paused, archived)
     */
    public function list(array $params = []): Page
    {
        return Page::fromResponse($this->requester->request('GET', '/journeys', ['query' => $params]));
    }

    /**
     * All journeys across pages.
     *
     * @param array<string, mixed> $params limit, status
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
     * Creates a draft: create(['name' => 'Welcome', 'graph' => $graph, 'entry' => $entry]).
     *
     * @param array<string, mixed> $journey name, description, graph, entry
     *
     * @return array<string, mixed>
     */
    public function create(array $journey, ?string $idempotencyKey = null): array
    {
        return $this->requester->request('POST', '/journeys', ['json' => $journey, 'idempotent' => true, 'idempotency_key' => $idempotencyKey]);
    }

    /**
     * The journey with its draft and the published version.
     *
     * @return array<string, mixed>
     */
    public function get(int $journey): array
    {
        return $this->requester->request('GET', $this->path('/journeys/{id}', ['id' => $journey]));
    }

    /**
     * Partial update of the draft: name, description, graph, entry.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public function update(int $journey, array $changes): array
    {
        return $this->requester->request('PATCH', $this->path('/journeys/{id}', ['id' => $journey]), ['json' => $changes]);
    }

    /**
     * Checks the draft without publishing: {"valid": true}, or a ValidationException with the problems.
     *
     * @return array<string, mixed>
     */
    public function validate(int $journey): array
    {
        return $this->requester->request('POST', $this->path('/journeys/{id}/validate', ['id' => $journey]));
    }

    /**
     * Publishes the draft as a new version.
     *
     * @return array<string, mixed>
     */
    public function publish(int $journey, ?string $idempotencyKey = null): array
    {
        return $this->action($journey, 'publish', null, $idempotencyKey);
    }

    /**
     * Pauses the journey: no new entries; participants stay unless $exitAll.
     *
     * @return array<string, mixed>
     */
    public function pause(int $journey, bool $exitAll = false, ?string $idempotencyKey = null): array
    {
        return $this->action($journey, 'pause', $exitAll ? ['exit_all' => true] : null, $idempotencyKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function resume(int $journey, ?string $idempotencyKey = null): array
    {
        return $this->action($journey, 'resume', null, $idempotencyKey);
    }

    /**
     * Archives the journey: all participants exit.
     *
     * @return array<string, mixed>
     */
    public function archive(int $journey, ?string $idempotencyKey = null): array
    {
        return $this->action($journey, 'archive', null, $idempotencyKey);
    }

    /**
     * Participations, newest first.
     *
     * @param array<string, mixed> $params limit (1–200, 50 by default), cursor, status (active, waiting, completed, exited, failed)
     */
    public function enrollments(int $journey, array $params = []): Page
    {
        return Page::fromResponse($this->requester->request('GET', $this->path('/journeys/{id}/enrollments', ['id' => $journey]), ['query' => $params]));
    }

    /**
     * All participations across pages.
     *
     * @param array<string, mixed> $params limit, status
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateEnrollments(int $journey, array $params = []): \Generator
    {
        return $this->paginate(function (?string $cursor) use ($journey, $params): Page {
            return $this->enrollments($journey, self::filled($params + ['cursor' => $cursor]));
        });
    }

    /**
     * Funnel, goals and A/B branches.
     *
     * @param int|null $period days: 7, 30 (default) or 90
     *
     * @return array<string, mixed>
     */
    public function report(int $journey, ?int $period = null): array
    {
        return $this->requester->request('GET', $this->path('/journeys/{id}/report', ['id' => $journey]), ['query' => ['period' => $period]]);
    }

    /**
     * Enrolls a profile into a journey with the API trigger: enroll(7, Identity::userId(42), ['order_id' => 'A-1001']).
     * $data is available to the journey's messages and conditions.
     *
     * @param array{type: string, value: string} $identity
     * @param array<string, mixed>               $data
     * @param string|null                        $idempotencyKey the same key enrolls once: a repeat returns the existing
     *                                                           enrollment (200 instead of 201)
     *
     * @return array<string, mixed> enrolled, enrollment_id, profile_id
     */
    public function enroll(int $journey, array $identity, array $data = [], ?string $idempotencyKey = null): array
    {
        $body = ['identity' => $identity];

        if ($data !== []) {
            $body['data'] = $data;
        }

        if ($idempotencyKey !== null) {
            $body['idempotency_key'] = $idempotencyKey;
        }

        return $this->requester->request('POST', $this->path('/journeys/{id}/enroll', ['id' => $journey]), [
            'json' => $body,
            'idempotent' => true,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function action(int $journey, string $action, ?array $body, ?string $idempotencyKey): array
    {
        $options = ['idempotent' => true, 'idempotency_key' => $idempotencyKey];

        if ($body !== null) {
            $options['json'] = $body;
        }

        return $this->requester->request('POST', $this->path('/journeys/{id}/' . $action, ['id' => $journey]), $options);
    }
}
