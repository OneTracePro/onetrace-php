<?php

declare(strict_types=1);

namespace OneTrace\Http;

use OneTrace\Exception\ApiException;
use OneTrace\Exception\ConfigurationException;
use OneTrace\Exception\RateLimitException;
use OneTrace\Exception\TransportException;
use OneTrace\Support\Uuid;

/**
 * Builds API requests, picks the key, decodes JSON and retries temporary failures.
 *
 * Retried: network errors, 429 (respecting Retry-After), 5xx and 409 for requests with an idempotency key.
 * Every API call is safe to repeat: events are deduplicated by messageId, creating and launching resources
 * carries an Idempotency-Key that stays the same across retries, the rest is idempotent by design.
 *
 * @internal
 */
final class Requester
{
    public const AUTH_WRITE = 'write';
    public const AUTH_SECRET = 'secret';

    private const MAX_RETRY_AFTER = 60.0;

    private string $baseUrl;

    private ?string $writeKey;

    private ?string $secretKey;

    private Transport $transport;

    private int $maxRetries;

    private float $retryDelay;

    private string $userAgent;

    private string $language;

    /** @var callable(float): void */
    private $sleep;

    /**
     * @param callable(float): void|null $sleep receives seconds; tests replace it to avoid waiting
     */
    public function __construct(
        string $baseUrl,
        ?string $writeKey,
        ?string $secretKey,
        Transport $transport,
        int $maxRetries,
        float $retryDelay,
        string $userAgent,
        ?callable $sleep = null,
        string $language = 'en'
    ) {
        $this->baseUrl = $baseUrl;
        $this->writeKey = $writeKey;
        $this->secretKey = $secretKey;
        $this->transport = $transport;
        $this->maxRetries = max(0, $maxRetries);
        $this->retryDelay = max(0.0, $retryDelay);
        $this->userAgent = $userAgent;
        $this->language = $language;
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1000000));
        };
    }

    /**
     * @param array{
     *     query?: array<string, mixed>,
     *     json?: array<string, mixed>,
     *     auth?: string,
     *     idempotent?: bool,
     *     idempotency_key?: string|null,
     * } $options auth: AUTH_WRITE accepts either key (write key preferred), AUTH_SECRET needs the secret key;
     *             idempotent: send an Idempotency-Key header (generated unless given)
     *
     * @return array<string, mixed> decoded JSON body; an empty array for 204
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function request(string $method, string $path, array $options = []): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Accept-Language' => $this->language,
            'Authorization' => 'Bearer ' . $this->key($options['auth'] ?? self::AUTH_SECRET),
            'User-Agent' => $this->userAgent,
        ];

        $body = null;

        if (\array_key_exists('json', $options)) {
            $headers['Content-Type'] = 'application/json';
            $body = Json::encode($options['json']);
        }

        if (!empty($options['idempotent']) || isset($options['idempotency_key'])) {
            $headers['Idempotency-Key'] = $options['idempotency_key'] ?? Uuid::v4();
        }

        $request = new Request($method, $this->url($path, $options['query'] ?? []), $headers, $body);
        $response = $this->sendWithRetries($request);

        if ($response->getStatus() === 204 || trim($response->getBody()) === '') {
            return [];
        }

        $decoded = json_decode($response->getBody(), true);

        if (!\is_array($decoded)) {
            throw new ApiException(sprintf('Unexpected response from %s %s: not a JSON object.', $method, $path), $response);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    private function sendWithRetries(Request $request): Response
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->transport->send($request);
            } catch (TransportException $e) {
                if ($attempt >= $this->maxRetries) {
                    throw $e;
                }

                ($this->sleep)($this->backoff($attempt));

                continue;
            }

            if ($response->getStatus() < 400) {
                return $response;
            }

            $error = ApiException::fromResponse($response);

            if ($attempt >= $this->maxRetries || !$this->retryable($response, $request)) {
                throw $error;
            }

            $delay = $this->backoff($attempt);

            if ($error instanceof RateLimitException && $error->getRetryAfter() !== null) {
                $delay = min((float) $error->getRetryAfter(), self::MAX_RETRY_AFTER);
            }

            ($this->sleep)($delay);
        }
    }

    private function retryable(Response $response, Request $request): bool
    {
        $status = $response->getStatus();

        return $status === 429 || $status >= 500 || ($status === 409 && $request->getHeader('Idempotency-Key') !== null);
    }

    /**
     * Exponential backoff with jitter: 0.5 s, 1 s, 2 s … (by default), capped at 8 s.
     */
    private function backoff(int $attempt): float
    {
        $delay = min($this->retryDelay * (2 ** $attempt), 8.0);

        return $delay * (0.5 + mt_rand() / mt_getrandmax() / 2);
    }

    private function key(string $auth): string
    {
        if ($auth === self::AUTH_WRITE && $this->writeKey !== null) {
            return $this->writeKey;
        }

        if ($this->secretKey !== null) {
            return $this->secretKey;
        }

        throw new ConfigurationException($auth === self::AUTH_WRITE
            ? 'Pass "write_key" or "secret_key" to the client to send events.'
            : 'This method needs a secret key: pass "secret_key" to the client.');
    }

    /**
     * @param array<string, mixed> $query
     */
    private function url(string $path, array $query): string
    {
        $url = $this->baseUrl . $path;
        $pairs = [];

        foreach ($query as $name => $value) {
            if ($value === null) {
                continue;
            }

            if (\is_array($value)) {
                foreach ($value as $item) {
                    $pairs[] = rawurlencode($name . '[]') . '=' . rawurlencode(self::scalar($item));
                }
            } else {
                $pairs[] = rawurlencode($name) . '=' . rawurlencode(self::scalar($value));
            }
        }

        return $pairs === [] ? $url : $url . '?' . implode('&', $pairs);
    }

    /**
     * @param mixed $value
     */
    private static function scalar($value): string
    {
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (!\is_scalar($value)) {
            throw new \InvalidArgumentException('Query parameters must be scalars or lists of scalars.');
        }

        return (string) $value;
    }
}
