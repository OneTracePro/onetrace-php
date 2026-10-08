<?php

declare(strict_types=1);

namespace OneTrace\Resource;

use OneTrace\Client;
use OneTrace\EventBuffer;
use OneTrace\Http\Json;
use OneTrace\Http\Requester;
use OneTrace\Support\Uuid;

/**
 * Event ingestion: track, identify, page, alias and batches. Works with a write key or a secret key.
 *
 * Each message gets a messageId (repeats with the same id within 24 hours are dropped by the server, so retries
 * are safe), a timestamp and context.library unless they are given. Responses are {"accepted": n, "duplicates": n};
 * the events are processed asynchronously.
 */
final class Events extends Resource
{
    public const TYPES = ['track', 'identify', 'page', 'alias'];

    /** Messages per request, limited by the API. */
    public const MAX_BATCH = 500;

    /** Bytes per batch request: the API accepts up to 1 MB, a margin is left for the envelope. */
    public const MAX_BATCH_BYTES = 900000;

    /**
     * An action of a user or a visitor:
     * track(['userId' => '42', 'event' => 'order_completed', 'properties' => ['order_id' => 'A-1001', 'amount' => 9980]]).
     *
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    public function track(array $message): array
    {
        return $this->send('track', $message);
    }

    /**
     * Links the visitor to a user and updates profile traits:
     * identify(['userId' => '42', 'anonymousId' => $cookie, 'traits' => ['email' => 'anna@example.com']]).
     *
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    public function identify(array $message): array
    {
        return $this->send('identify', $message);
    }

    /**
     * A page view: page(['anonymousId' => $cookie, 'name' => 'Checkout', 'properties' => ['url' => $url]]).
     *
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    public function page(array $message): array
    {
        return $this->send('page', $message);
    }

    /**
     * Merges a previous identifier into a user: alias(['previousId' => $oldId, 'userId' => '42']).
     *
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    public function alias(array $message): array
    {
        return $this->send('alias', $message);
    }

    /**
     * Sends many messages; each needs "type" (track, identify, page, alias). Split into requests of up to
     * 500 messages and 1 MB automatically. Returns the summed counters.
     *
     * @param iterable<array<string, mixed>> $messages
     *
     * @return array{accepted: int, duplicates: int}
     */
    public function batch(iterable $messages): array
    {
        $prepared = [];

        foreach ($messages as $index => $message) {
            $type = $message['type'] ?? null;

            if (!\is_string($type) || !\in_array($type, self::TYPES, true)) {
                throw new \InvalidArgumentException(sprintf('Message %s needs "type": one of %s.', \is_scalar($index) ? (string) $index : '?', implode(', ', self::TYPES)));
            }

            $prepared[] = self::prepare($type, $message);
        }

        return $this->sendPrepared($prepared);
    }

    /**
     * A buffer that collects messages and sends them in batches: when $flushAt messages are queued, on flush()
     * and when the buffer is destroyed (end of the script). Errors of the final automatic flush go to $onError
     * (or a PHP warning) instead of being thrown from the destructor.
     *
     * @param callable(\Throwable, list<array<string, mixed>>): void|null $onError receives the error and the lost messages
     */
    public function buffer(int $flushAt = 100, ?callable $onError = null): EventBuffer
    {
        return new EventBuffer($this, $flushAt, $onError);
    }

    /**
     * Sends messages already prepared by prepare().
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return array{accepted: int, duplicates: int}
     *
     * @internal
     */
    public function sendPrepared(array $messages): array
    {
        $result = ['accepted' => 0, 'duplicates' => 0];

        foreach (self::chunks($messages) as $chunk) {
            $response = $this->requester->request('POST', '/batch', ['auth' => self::auth($chunk), 'json' => ['batch' => $chunk]]);
            $result['accepted'] += self::count($response['accepted'] ?? 0);
            $result['duplicates'] += self::count($response['duplicates'] ?? 0);
        }

        return $result;
    }

    /**
     * Validates a message and fills messageId, timestamp and context.library.
     *
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     *
     * @internal
     */
    public static function prepare(string $type, array $message): array
    {
        $message['type'] = $type;

        if ($type === 'alias') {
            if (self::blank($message['userId'] ?? null) || self::blank($message['previousId'] ?? null)) {
                throw new \InvalidArgumentException('alias needs "userId" and "previousId".');
            }
        } elseif (self::blank($message['userId'] ?? null) && self::blank($message['anonymousId'] ?? null)) {
            throw new \InvalidArgumentException(sprintf('%s needs "userId" or "anonymousId".', $type));
        }

        if ($type === 'track' && (!isset($message['event']) || !\is_string($message['event']) || trim($message['event']) === '')) {
            throw new \InvalidArgumentException('track needs a non-empty "event" name.');
        }

        foreach (['userId', 'anonymousId', 'previousId'] as $field) {
            if (isset($message[$field]) && \is_int($message[$field])) {
                $message[$field] = (string) $message[$field];
            }
        }

        if (self::blank($message['messageId'] ?? null)) {
            $message['messageId'] = Uuid::v4();
        }

        $timestamp = $message['timestamp'] ?? null;

        if ($timestamp instanceof \DateTimeInterface) {
            $message['timestamp'] = $timestamp->format('Y-m-d\TH:i:s.vP');
        } elseif (self::blank($timestamp)) {
            $message['timestamp'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        }

        $context = isset($message['context']) && \is_array($message['context']) ? $message['context'] : [];
        $context['library'] = $context['library'] ?? ['name' => 'onetrace-php', 'version' => Client::VERSION];
        $message['context'] = $context;

        // Properties and traits are JSON objects even when empty: [] would be encoded as an array.
        foreach (['properties', 'traits'] as $field) {
            if (isset($message[$field]) && $message[$field] === []) {
                $message[$field] = new \stdClass();
            }
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function send(string $type, array $message): array
    {
        return $this->requester->request('POST', '/' . $type, ['auth' => self::auth([$message]), 'json' => self::prepare($type, $message)]);
    }

    /**
     * Consents in identify are accepted only with the secret key; other messages prefer the write key.
     *
     * @param array<array<string, mixed>> $messages
     */
    private static function auth(array $messages): string
    {
        foreach ($messages as $message) {
            if (!empty($message['consents'])) {
                return Requester::AUTH_SECRET;
            }
        }

        return Requester::AUTH_WRITE;
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return \Generator<int, list<array<string, mixed>>>
     */
    private static function chunks(array $messages): \Generator
    {
        $chunk = [];
        $bytes = 0;

        foreach ($messages as $message) {
            $size = \strlen(Json::encode($message)) + 1;

            if ($size > self::MAX_BATCH_BYTES) {
                throw new \InvalidArgumentException(sprintf('Message %s is larger than %d bytes.', \is_string($message['messageId'] ?? null) ? $message['messageId'] : '', self::MAX_BATCH_BYTES));
            }

            if ($chunk !== [] && (\count($chunk) >= self::MAX_BATCH || $bytes + $size > self::MAX_BATCH_BYTES)) {
                yield $chunk;
                $chunk = [];
                $bytes = 0;
            }

            $chunk[] = $message;
            $bytes += $size;
        }

        if ($chunk !== []) {
            yield $chunk;
        }
    }

    /**
     * @param mixed $value
     */
    private static function count($value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param mixed $value
     */
    private static function blank($value): bool
    {
        return $value === null || (\is_string($value) && trim($value) === '');
    }
}
