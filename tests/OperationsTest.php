<?php

declare(strict_types=1);

namespace OneTrace\Tests;

use OneTrace\Client;
use OneTrace\Identity;
use OneTrace\Tests\Support\ClientFactory;
use OneTrace\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Every operation of the OpenAPI specification is called through the library and must produce the documented
 * HTTP method and path with the documented key type.
 *
 * The specification is tests/Fixtures/openapi.json; set ONETRACE_SPEC_URL (or ONETRACE_SPEC_FILE) to check the
 * library against a live platform instead.
 */
final class OperationsTest extends TestCase
{
    use ClientFactory;

    /**
     * @return array<string, callable(Client): mixed>
     */
    public static function calls(): array
    {
        $identity = Identity::email('anna@example.com');

        return [
            'ingest.track' => static function (Client $c) { return $c->events()->track(['userId' => '42', 'event' => 'order_completed']); },
            'ingest.identify' => static function (Client $c) { return $c->events()->identify(['userId' => '42', 'traits' => ['email' => 'anna@example.com']]); },
            'ingest.page' => static function (Client $c) { return $c->events()->page(['anonymousId' => 'a-1', 'name' => 'Home']); },
            'ingest.alias' => static function (Client $c) { return $c->events()->alias(['previousId' => 'a-1', 'userId' => '42']); },
            'ingest.batch' => static function (Client $c) { return $c->events()->batch([['type' => 'track', 'userId' => '42', 'event' => 'x']]); },
            'push.config' => static function (Client $c) { return $c->push()->config(); },
            'push.subscribe' => static function (Client $c) { return $c->push()->subscribe(['endpoint' => 'https://fcm.googleapis.com/fcm/send/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']], ['anonymousId' => 'a-1']); },
            'push.unsubscribe' => static function (Client $c) { return $c->push()->unsubscribe('https://fcm.googleapis.com/fcm/send/x'); },
            'recommendations.get' => static function (Client $c) { return $c->recommendations()->get('viewed_with', ['item' => 'SKU-1']); },
            'widgets.get' => static function (Client $c) { return $c->widgets()->get('abc123def456', ['item' => 'SKU-1']); },
            'search.products' => static function (Client $c) { return $c->search()->products('linen dress', ['brand' => 'Contoso', 'all' => true]); },
            'search.suggest' => static function (Client $c) { return $c->search()->suggest('lin'); },
            'meta.openapi' => static function (Client $c) { return $c->openApi(); },
            'key.show' => static function (Client $c) { return $c->checkKey('write'); },
            'profiles.show' => static function (Client $c) { return $c->profiles()->get('email', 'anna@example.com'); },
            'profiles.destroy' => static function (Client $c) { $c->profiles()->delete('user_id', '42'); },
            'profiles.events' => static function (Client $c) { return $c->profiles()->events('user_id', '42', ['name' => 'order_completed']); },
            'profiles.consents' => static function (Client $c) { return $c->profiles()->updateConsent('email', 'anna@example.com', 'email', 'unsubscribed', 'news'); },
            'profiles.telegramLink' => static function (Client $c) use ($identity) { return $c->profiles()->telegramLink($identity); },
            'profiles.viberLink' => static function (Client $c) use ($identity) { return $c->profiles()->viberLink($identity); },
            'products.upsert' => static function (Client $c) { return $c->products()->upsert([['id' => 'SKU-1', 'name' => 'Sneakers']]); },
            'products.delete' => static function (Client $c) { return $c->products()->delete(['SKU-1']); },
            'catalog.events' => static function (Client $c) { return $c->catalog()->events(); },
            'catalog.traits' => static function (Client $c) { return $c->catalog()->traits(); },
            'segments.index' => static function (Client $c) { return $c->segments()->list(['limit' => 10]); },
            'segments.store' => static function (Client $c) { return $c->segments()->create(['name' => 'VIP', 'type' => 'static']); },
            'segments.show' => static function (Client $c) { return $c->segments()->get(5); },
            'segments.update' => static function (Client $c) { return $c->segments()->update(5, ['name' => 'VIP+']); },
            'segments.destroy' => static function (Client $c) { $c->segments()->delete(5); },
            'segments.compute' => static function (Client $c) { return $c->segments()->compute(5); },
            'segments.members' => static function (Client $c) { return $c->segments()->members(5); },
            'segments.addMembers' => static function (Client $c) use ($identity) { return $c->segments()->addMembers(5, [$identity]); },
            'segments.removeMembers' => static function (Client $c) use ($identity) { return $c->segments()->removeMembers(5, [$identity]); },
            'segments.membership' => static function (Client $c) { return $c->segments()->membership(5, 'phone', '+4915112345678'); },
            'journeys.index' => static function (Client $c) { return $c->journeys()->list(['status' => 'active']); },
            'journeys.store' => static function (Client $c) { return $c->journeys()->create(['name' => 'Welcome']); },
            'journeys.show' => static function (Client $c) { return $c->journeys()->get(7); },
            'journeys.update' => static function (Client $c) { return $c->journeys()->update(7, ['name' => 'Welcome 2']); },
            'journeys.validate' => static function (Client $c) { return $c->journeys()->validate(7); },
            'journeys.publish' => static function (Client $c) { return $c->journeys()->publish(7); },
            'journeys.pause' => static function (Client $c) { return $c->journeys()->pause(7, true); },
            'journeys.resume' => static function (Client $c) { return $c->journeys()->resume(7); },
            'journeys.archive' => static function (Client $c) { return $c->journeys()->archive(7); },
            'journeys.enrollments' => static function (Client $c) { return $c->journeys()->enrollments(7, ['status' => 'waiting']); },
            'journeys.report' => static function (Client $c) { return $c->journeys()->report(7, 90); },
            'journeys.enroll' => static function (Client $c) { return $c->journeys()->enroll(7, Identity::userId(42), ['order_id' => 'A-1']); },
            'campaigns.index' => static function (Client $c) { return $c->campaigns()->list(); },
            'campaigns.store' => static function (Client $c) { return $c->campaigns()->create(['name' => 'Sale', 'audience' => [], 'variants' => [], 'schedule' => ['type' => 'now']]); },
            'campaigns.show' => static function (Client $c) { return $c->campaigns()->get(3); },
            'campaigns.update' => static function (Client $c) { return $c->campaigns()->update(3, ['name' => 'Sale 2']); },
            'campaigns.destroy' => static function (Client $c) { $c->campaigns()->delete(3); },
            'campaigns.report' => static function (Client $c) { return $c->campaigns()->report(3); },
            'campaigns.schedule' => static function (Client $c) { return $c->campaigns()->schedule(3); },
            'campaigns.pause' => static function (Client $c) { return $c->campaigns()->pause(3); },
            'campaigns.resume' => static function (Client $c) { return $c->campaigns()->resume(3); },
            'campaigns.cancel' => static function (Client $c) { return $c->campaigns()->cancel(3); },
            'templates.index' => static function (Client $c) { return $c->templates()->list(); },
            'templates.store' => static function (Client $c) { return $c->templates()->create(['name' => 'Welcome', 'channel_key' => 'email', 'content' => []]); },
            'templates.show' => static function (Client $c) { return $c->templates()->get(3); },
            'templates.update' => static function (Client $c) { return $c->templates()->update(3, ['name' => 'Welcome 2']); },
            'templates.destroy' => static function (Client $c) { $c->templates()->delete(3); },
        ];
    }

    /**
     * @return array<string, array{string, string, string, list<string>, bool}>
     */
    public static function operations(): array
    {
        $operations = [];

        foreach (self::spec()['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (!\is_array($operation) || !isset($operation['operationId'])) {
                    continue;
                }

                $security = [];

                foreach ($operation['security'] ?? [] as $scheme) {
                    $security = array_merge($security, array_keys($scheme));
                }

                $idempotent = false;

                foreach ($operation['parameters'] ?? [] as $parameter) {
                    $idempotent = $idempotent || ($parameter['$ref'] ?? '') === '#/components/parameters/IdempotencyKey';
                }

                $operations[$operation['operationId']] = [$operation['operationId'], strtoupper($method), $path, $security, $idempotent];
            }
        }

        return $operations;
    }

    public function testEveryOperationOfTheSpecificationHasAMethod(): void
    {
        $operations = array_keys(self::operations());
        sort($operations);
        $mapped = array_keys(Client::OPERATIONS);
        sort($mapped);

        self::assertSame($operations, $mapped, 'Client::OPERATIONS must list exactly the operations of the specification.');

        $called = array_keys(self::calls());
        sort($called);
        self::assertSame($operations, $called, 'Every operation needs a call in OperationsTest::calls().');
    }

    public function testOperationMapPointsToPublicMethods(): void
    {
        $client = new Client('https://cdp.example.com', ['secret_key' => 'cdp_sk_test', 'transport' => new FakeTransport()]);

        foreach (Client::OPERATIONS as $operationId => $target) {
            $parts = explode('.', $target);
            $object = $client;

            if (\count($parts) === 2) {
                $object = $client->{$parts[0]}();
            }

            self::assertTrue(method_exists($object, end($parts)), sprintf('%s → %s does not exist.', $operationId, $target));
        }
    }

    /**
     * @dataProvider operations
     *
     * @param list<string> $security
     */
    public function testOperationSendsTheDocumentedRequest(string $operationId, string $method, string $path, array $security, bool $idempotent): void
    {
        $transport = new FakeTransport();
        $transport->push(200, ['data' => [], 'next_cursor' => null]);
        $call = self::calls()[$operationId];
        $call($this->client($transport));

        self::assertCount(1, $transport->requests);
        $request = $transport->last();
        $pattern = '#^https://cdp\.example\.com/api/v1' . preg_replace('#\\\\\{[a-z]+\\\\\}#', '[^/?]+', preg_quote($path, '#')) . '(\?.*)?$#';

        self::assertSame($method, $request->getMethod());
        self::assertMatchesRegularExpression($pattern, $request->getUrl());

        // Ingestion accepts the write key: the client prefers it; everything else needs the secret key.
        $expectedKey = \in_array('writeKey', $security, true) ? 'cdp_wk_test' : 'cdp_sk_test';

        if ($security !== []) {
            self::assertSame('Bearer ' . $expectedKey, $request->getHeader('Authorization'));
        }

        if ($idempotent) {
            self::assertNotNull($request->getHeader('Idempotency-Key'), 'Operations that accept Idempotency-Key must send one.');
        }
    }

    /**
     * @return array{paths: array<string, array<string, mixed>>}
     */
    private static function spec(): array
    {
        $source = getenv('ONETRACE_SPEC_URL') ?: getenv('ONETRACE_SPEC_FILE') ?: __DIR__ . '/Fixtures/openapi.json';
        $json = file_get_contents($source);

        if ($json === false) {
            throw new \RuntimeException('Cannot read the specification from ' . $source);
        }

        /** @var array{paths: array<string, array<string, mixed>>} $spec */
        $spec = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $spec;
    }
}
