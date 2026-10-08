<?php

declare(strict_types=1);

namespace OneTrace\Resource;

use OneTrace\Page;

/**
 * Message templates with language versions. Needs a secret key with templates.read or templates.write.
 *
 * A template has the main version in "content" (language "language") and other versions in "translations"
 * (language tag => content); recipients get the version for their profile language, otherwise the main one.
 */
final class Templates extends Resource
{
    /**
     * @param array<string, mixed> $params limit (1–200, 50 by default), cursor, channel
     */
    public function list(array $params = []): Page
    {
        return Page::fromResponse($this->requester->request('GET', '/templates', ['query' => $params]));
    }

    /**
     * All templates across pages.
     *
     * @param array<string, mixed> $params limit, channel
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
     * Creates a template: name, channel_key and content are required; topic, transactional, language and
     * translations are optional.
     *
     *     create(['name' => 'Welcome', 'channel_key' => 'email', 'language' => 'en',
     *             'content' => ['subject' => 'Hello', 'html' => '<p>Hello</p>'],
     *             'translations' => ['de' => ['subject' => 'Hallo', 'html' => '<p>Hallo</p>']]]);
     *
     * @param array<string, mixed> $template
     *
     * @return array<string, mixed>
     */
    public function create(array $template, ?string $idempotencyKey = null): array
    {
        return $this->requester->request('POST', '/templates', ['json' => $template, 'idempotent' => true, 'idempotency_key' => $idempotencyKey]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $template): array
    {
        return $this->requester->request('GET', $this->path('/templates/{id}', ['id' => $template]));
    }

    /**
     * Partial update; without "translations" the stored language versions are kept.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public function update(int $template, array $changes): array
    {
        return $this->requester->request('PATCH', $this->path('/templates/{id}', ['id' => $template]), ['json' => $changes]);
    }

    public function delete(int $template): void
    {
        $this->requester->request('DELETE', $this->path('/templates/{id}', ['id' => $template]));
    }
}
