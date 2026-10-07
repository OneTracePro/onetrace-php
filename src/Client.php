<?php

declare(strict_types=1);

namespace OneTrace;

use OneTrace\Http\CurlTransport;
use OneTrace\Http\Psr18Transport;
use OneTrace\Http\Requester;
use OneTrace\Http\Transport;
use OneTrace\Resource\Campaigns;
use OneTrace\Resource\Catalog;
use OneTrace\Resource\Events;
use OneTrace\Resource\Journeys;
use OneTrace\Resource\Products;
use OneTrace\Resource\Profiles;
use OneTrace\Resource\Push;
use OneTrace\Resource\Recommendations;
use OneTrace\Resource\Resource;
use OneTrace\Resource\Segments;
use OneTrace\Resource\Widgets;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Client of the OneTrace.pro public API v1.
 *
 *     $client = new OneTrace\Client('https://cdp.onetrace.pro', [
 *         'write_key' => getenv('ONETRACE_WRITE_KEY'),   // events
 *         'secret_key' => getenv('ONETRACE_SECRET_KEY'), // everything else
 *     ]);
 *     $client->events()->track(['userId' => '42', 'event' => 'order_completed', 'properties' => ['amount' => 9980]]);
 *
 * The address is the platform domain of your account (a white-label brand has its own); "/api/v1" is added
 * when missing.
 */
final class Client
{
    public const VERSION = '1.1.0';

    /**
     * API operations (operationId of the OpenAPI specification) and the methods that call them.
     */
    public const OPERATIONS = [
        'ingest.track' => 'events.track',
        'ingest.identify' => 'events.identify',
        'ingest.page' => 'events.page',
        'ingest.alias' => 'events.alias',
        'ingest.batch' => 'events.batch',
        'push.config' => 'push.config',
        'push.subscribe' => 'push.subscribe',
        'push.unsubscribe' => 'push.unsubscribe',
        'recommendations.get' => 'recommendations.get',
        'widgets.get' => 'widgets.get',
        'meta.openapi' => 'openApi',
        'profiles.show' => 'profiles.get',
        'profiles.destroy' => 'profiles.delete',
        'profiles.events' => 'profiles.events',
        'profiles.consents' => 'profiles.updateConsent',
        'profiles.telegramLink' => 'profiles.telegramLink',
        'profiles.viberLink' => 'profiles.viberLink',
        'products.upsert' => 'products.upsert',
        'products.delete' => 'products.delete',
        'catalog.events' => 'catalog.events',
        'catalog.traits' => 'catalog.traits',
        'segments.index' => 'segments.list',
        'segments.store' => 'segments.create',
        'segments.show' => 'segments.get',
        'segments.update' => 'segments.update',
        'segments.destroy' => 'segments.delete',
        'segments.compute' => 'segments.compute',
        'segments.members' => 'segments.members',
        'segments.addMembers' => 'segments.addMembers',
        'segments.removeMembers' => 'segments.removeMembers',
        'segments.membership' => 'segments.membership',
        'journeys.index' => 'journeys.list',
        'journeys.store' => 'journeys.create',
        'journeys.show' => 'journeys.get',
        'journeys.update' => 'journeys.update',
        'journeys.validate' => 'journeys.validate',
        'journeys.publish' => 'journeys.publish',
        'journeys.pause' => 'journeys.pause',
        'journeys.resume' => 'journeys.resume',
        'journeys.archive' => 'journeys.archive',
        'journeys.enrollments' => 'journeys.enrollments',
        'journeys.report' => 'journeys.report',
        'journeys.enroll' => 'journeys.enroll',
        'campaigns.index' => 'campaigns.list',
        'campaigns.store' => 'campaigns.create',
        'campaigns.show' => 'campaigns.get',
        'campaigns.update' => 'campaigns.update',
        'campaigns.destroy' => 'campaigns.delete',
        'campaigns.report' => 'campaigns.report',
        'campaigns.schedule' => 'campaigns.schedule',
        'campaigns.pause' => 'campaigns.pause',
        'campaigns.resume' => 'campaigns.resume',
        'campaigns.cancel' => 'campaigns.cancel',
    ];

    private Requester $requester;

    /** @var array<class-string<Resource>, Resource> */
    private array $resources = [];

    /**
     * @param string $baseUrl platform address, e.g. https://cdp.onetrace.pro
     * @param array{
     *     write_key?: string|null,
     *     secret_key?: string|null,
     *     timeout?: float,
     *     connect_timeout?: float,
     *     max_retries?: int,
     *     retry_delay?: float,
     *     transport?: Transport,
     *     http_client?: ClientInterface,
     *     request_factory?: RequestFactoryInterface,
     *     stream_factory?: StreamFactoryInterface,
     *     user_agent?: string,
     *     language?: string,
     *     sleep?: callable(float): void,
     * } $options
     *   - write_key: cdp_wk_… for events, recommendations, widgets and Web Push;
     *   - secret_key: cdp_sk_… for everything (never expose it to browsers);
     *   - timeout / connect_timeout: seconds for the built-in cURL transport (10 and 5 by default);
     *   - max_retries: retries of network errors, 429, 5xx (3 by default, 0 disables);
     *   - retry_delay: first backoff delay in seconds, doubled on each retry (0.5 by default);
     *   - transport: your own OneTrace\Http\Transport;
     *   - http_client + request_factory + stream_factory: send through a PSR-18 client instead of cURL;
     *   - user_agent: appended to the library's User-Agent, e.g. "my-shop/2.1";
     *   - language: language of error messages from the API (Accept-Language), "en" by default.
     */
    public function __construct(string $baseUrl, array $options = [])
    {
        $baseUrl = rtrim(trim($baseUrl), '/');

        if (!preg_match('#^https?://[^/\s]+#i', $baseUrl)) {
            throw new \InvalidArgumentException(sprintf('The platform address must be an http(s) URL, e.g. https://cdp.onetrace.pro (got "%s").', $baseUrl));
        }

        if (!preg_match('#/api/v1$#', $baseUrl)) {
            $baseUrl .= '/api/v1';
        }

        $writeKey = self::key($options['write_key'] ?? null);
        $secretKey = self::key($options['secret_key'] ?? null);

        if ($writeKey === null && $secretKey === null) {
            throw new \InvalidArgumentException('Pass "write_key", "secret_key" or both.');
        }

        $userAgent = sprintf('onetrace-php/%s PHP/%s', self::VERSION, PHP_VERSION);

        if (isset($options['user_agent']) && $options['user_agent'] !== '') {
            $userAgent .= ' ' . $options['user_agent'];
        }

        $this->requester = new Requester(
            $baseUrl,
            $writeKey,
            $secretKey,
            self::transport($options),
            (int) ($options['max_retries'] ?? 3),
            (float) ($options['retry_delay'] ?? 0.5),
            $userAgent,
            $options['sleep'] ?? null,
            isset($options['language']) && $options['language'] !== '' ? $options['language'] : 'en'
        );
    }

    /**
     * Event ingestion: track, identify, page, alias, batch and a batching buffer.
     */
    public function events(): Events
    {
        return $this->resource(Events::class);
    }

    /**
     * Profiles, consents and Telegram / Viber deep links.
     */
    public function profiles(): Profiles
    {
        return $this->resource(Profiles::class);
    }

    /**
     * Product catalog.
     */
    public function products(): Products
    {
        return $this->resource(Products::class);
    }

    /**
     * Events and traits seen in the project.
     */
    public function catalog(): Catalog
    {
        return $this->resource(Catalog::class);
    }

    /**
     * Product recommendations.
     */
    public function recommendations(): Recommendations
    {
        return $this->resource(Recommendations::class);
    }

    /**
     * Website widgets configured in the admin panel.
     */
    public function widgets(): Widgets
    {
        return $this->resource(Widgets::class);
    }

    /**
     * Web Push subscriptions.
     */
    public function push(): Push
    {
        return $this->resource(Push::class);
    }

    /**
     * Segments and their members.
     */
    public function segments(): Segments
    {
        return $this->resource(Segments::class);
    }

    /**
     * Journeys.
     */
    public function journeys(): Journeys
    {
        return $this->resource(Journeys::class);
    }

    /**
     * Campaigns.
     */
    public function campaigns(): Campaigns
    {
        return $this->resource(Campaigns::class);
    }

    /**
     * The OpenAPI specification of the platform's API.
     *
     * @return array<string, mixed>
     */
    public function openApi(): array
    {
        return $this->requester->request('GET', '/openapi.json', ['auth' => Requester::AUTH_WRITE]);
    }

    /**
     * The API address in use, ending with /api/v1.
     */
    public function getBaseUrl(): string
    {
        return $this->requester->baseUrl();
    }

    /**
     * @template T of Resource
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function resource(string $class): Resource
    {
        if (!isset($this->resources[$class])) {
            $this->resources[$class] = new $class($this->requester);
        }

        /** @var T $resource */
        $resource = $this->resources[$class];

        return $resource;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function transport(array $options): Transport
    {
        if (isset($options['transport'])) {
            if (!$options['transport'] instanceof Transport) {
                throw new \InvalidArgumentException('"transport" must implement OneTrace\Http\Transport.');
            }

            return $options['transport'];
        }

        if (isset($options['http_client'])) {
            if (!isset($options['request_factory'], $options['stream_factory'])) {
                throw new \InvalidArgumentException('A PSR-18 "http_client" needs "request_factory" and "stream_factory" (PSR-17), e.g. new Nyholm\Psr7\Factory\Psr17Factory() for both.');
            }

            if (!$options['http_client'] instanceof ClientInterface || !$options['request_factory'] instanceof RequestFactoryInterface || !$options['stream_factory'] instanceof StreamFactoryInterface) {
                throw new \InvalidArgumentException('"http_client" must be a PSR-18 client, "request_factory" and "stream_factory" — PSR-17 factories.');
            }

            return new Psr18Transport($options['http_client'], $options['request_factory'], $options['stream_factory']);
        }

        return new CurlTransport(self::seconds($options['timeout'] ?? 10.0), self::seconds($options['connect_timeout'] ?? 5.0));
    }

    /**
     * @param mixed $value
     */
    private static function seconds($value): float
    {
        if (!is_numeric($value) || (float) $value <= 0) {
            throw new \InvalidArgumentException('Timeouts must be positive numbers of seconds.');
        }

        return (float) $value;
    }

    /**
     * @param mixed $key
     */
    private static function key($key): ?string
    {
        if ($key === null || $key === false || $key === '') {
            return null;
        }

        if (!\is_string($key)) {
            throw new \InvalidArgumentException('API keys must be strings.');
        }

        return trim($key);
    }
}
