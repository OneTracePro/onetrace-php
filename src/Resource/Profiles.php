<?php

declare(strict_types=1);

namespace OneTrace\Resource;

use OneTrace\Page;

/**
 * Profiles by any identifier (see OneTrace\Identity for types), consents and messenger deep links.
 * Needs a secret key with profiles.read, profiles.write or profiles.delete.
 */
final class Profiles extends Resource
{
    /**
     * The profile with traits and identities: get('email', 'anna@example.com').
     * Personal data is masked unless the key has the profiles.pii permission.
     *
     * @return array<string, mixed>
     */
    public function get(string $type, string $value): array
    {
        return $this->requester->request('GET', $this->profilePath($type, $value));
    }

    /**
     * Erases the profile and all profiles merged into it (GDPR right to erasure).
     */
    public function delete(string $type, string $value): void
    {
        $this->requester->request('DELETE', $this->profilePath($type, $value));
    }

    /**
     * Events of the profile, newest first, 50 per page.
     *
     * @param array{before?: \DateTimeInterface|string, name?: string} $params before: events older than this time
     *                                                                       (pass getNextCursor() of the previous page);
     *                                                                       name: only this event
     */
    public function events(string $type, string $value, array $params = []): Page
    {
        $response = $this->requester->request('GET', $this->profilePath($type, $value) . '/events', ['query' => $params]);

        return Page::fromResponse($response, 'next_before');
    }

    /**
     * All events of the profile across pages, newest first.
     *
     * @param array{before?: \DateTimeInterface|string, name?: string} $params
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateEvents(string $type, string $value, array $params = []): \Generator
    {
        $before = $params['before'] ?? null;

        return $this->paginate(function (?string $cursor) use ($type, $value, $params): Page {
            if ($cursor !== null) {
                $params['before'] = $cursor;
            }

            return $this->events($type, $value, $params);
        }, $before instanceof \DateTimeInterface ? $before->format(\DateTimeInterface::ATOM) : $before);
    }

    /**
     * Subscribes or unsubscribes the profile: updateConsent('email', 'anna@example.com', 'email', 'unsubscribed', 'news').
     *
     * @param string      $channel email, sms, web_push, telegram, whatsapp, viber …
     * @param string      $status  subscribed or unsubscribed
     * @param string|null $topic   subscription topic; null for the whole channel
     *
     * @return array<string, mixed>
     */
    public function updateConsent(string $type, string $value, string $channel, string $status, ?string $topic = null): array
    {
        return $this->requester->request('PUT', $this->profilePath($type, $value) . '/consents', [
            'json' => self::filled(['channel' => $channel, 'topic' => $topic, 'status' => $status]),
        ]);
    }

    /**
     * A Telegram deep link that connects the profile to the bot: telegramLink(Identity::userId(42)).
     *
     * @param array{type: string, value: string} $identity
     * @param string|null                        $integration bot integration key when the project has several
     *
     * @return array<string, mixed> url, token, integration
     */
    public function telegramLink(array $identity, ?string $integration = null): array
    {
        return $this->requester->request('POST', '/telegram/link', ['json' => self::filled(['identity' => $identity, 'integration' => $integration])]);
    }

    /**
     * A Viber deep link that connects the profile to the bot.
     *
     * @param array{type: string, value: string} $identity
     *
     * @return array<string, mixed> url, token, integration
     */
    public function viberLink(array $identity, ?string $integration = null): array
    {
        return $this->requester->request('POST', '/viber/link', ['json' => self::filled(['identity' => $identity, 'integration' => $integration])]);
    }

    private function profilePath(string $type, string $value): string
    {
        $identity = $this->identity($type, $value);

        return $this->path('/profiles/{type}/{value}', $identity);
    }
}
