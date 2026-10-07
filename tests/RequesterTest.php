<?php

declare(strict_types=1);

namespace OneTrace\Tests;

use OneTrace\Exception\ApiException;
use OneTrace\Exception\AuthenticationException;
use OneTrace\Exception\ConflictException;
use OneTrace\Exception\NotFoundException;
use OneTrace\Exception\OneTraceException;
use OneTrace\Exception\PayloadTooLargeException;
use OneTrace\Exception\PaymentRequiredException;
use OneTrace\Exception\PermissionException;
use OneTrace\Exception\RateLimitException;
use OneTrace\Exception\ServerException;
use OneTrace\Exception\TransportException;
use OneTrace\Exception\ValidationException;
use OneTrace\Identity;
use OneTrace\Tests\Support\ClientFactory;
use OneTrace\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Retries, errors and request encoding shared by all methods.
 */
final class RequesterTest extends TestCase
{
    use ClientFactory;

    public function testRetriesServerErrorsWithTheSameIdempotencyKey(): void
    {
        $transport = (new FakeTransport())->push(503)->push(502)->push(201, ['id' => 5]);

        $segment = $this->client($transport)->segments()->create(['name' => 'VIP', 'type' => 'static']);

        self::assertSame(['id' => 5], $segment);
        self::assertCount(3, $transport->requests);
        $keys = array_map(static function ($request) {
            return $request->getHeader('Idempotency-Key');
        }, $transport->requests);
        self::assertCount(1, array_unique($keys));
        self::assertNotNull($keys[0]);
        self::assertCount(2, $this->sleeps);
        self::assertGreaterThan($this->sleeps[0], $this->sleeps[1] * 1.5, 'The delay grows between retries.');
    }

    public function testUsesTheGivenIdempotencyKey(): void
    {
        $transport = (new FakeTransport())->push(201, ['enrolled' => true]);

        $this->client($transport)->journeys()->enroll(7, Identity::userId(42), [], 'order-A-1001');

        self::assertSame('order-A-1001', $transport->last()->getHeader('Idempotency-Key'));
        self::assertSame('order-A-1001', $transport->lastJson()['idempotency_key']);
    }

    public function testRespectsRetryAfterOn429(): void
    {
        $transport = (new FakeTransport())->push(429, ['message' => 'Too Many Attempts.'], ['Retry-After' => '7'])->push(202, ['accepted' => 1]);

        $this->client($transport)->events()->track(['userId' => '42', 'event' => 'x']);

        self::assertSame([7.0], $this->sleeps);
        self::assertSame(
            json_decode((string) $transport->requests[0]->getBody(), true)['messageId'],
            json_decode((string) $transport->requests[1]->getBody(), true)['messageId'],
            'A retried event keeps its messageId.'
        );
    }

    public function testRetriesNetworkErrorsAndGivesUpAfterMaxRetries(): void
    {
        $transport = (new FakeTransport())
            ->fail(new TransportException('timeout'))
            ->fail(new TransportException('timeout'))
            ->fail(new TransportException('timeout'));

        try {
            $this->client($transport, ['max_retries' => 2])->segments()->get(5);
            self::fail('A TransportException was expected.');
        } catch (TransportException $e) {
            self::assertInstanceOf(OneTraceException::class, $e);
        }

        self::assertCount(3, $transport->requests);
    }

    public function testDoesNotRetryClientErrors(): void
    {
        $transport = (new FakeTransport())->push(422, ['message' => 'The name field is required.', 'errors' => ['name' => ['The name field is required.']]]);

        try {
            $this->client($transport)->segments()->create(['type' => 'static']);
            self::fail('A ValidationException was expected.');
        } catch (ValidationException $e) {
            self::assertSame('The name field is required.', $e->getMessage());
            self::assertSame(422, $e->getStatusCode());
            self::assertSame(['name' => ['The name field is required.']], $e->getErrors());
        }

        self::assertCount(1, $transport->requests);
        self::assertSame([], $this->sleeps);
    }

    public function testRetriesConflictsOnlyWithAnIdempotencyKey(): void
    {
        $transport = (new FakeTransport())->push(409, ['message' => 'In progress'])->push(200, ['id' => 7]);
        $this->client($transport)->journeys()->publish(7);
        self::assertCount(2, $transport->requests);

        $transport = (new FakeTransport())->push(409, ['message' => 'Running']);
        $this->expectException(ConflictException::class);

        try {
            $this->client($transport)->segments()->delete(5);
        } finally {
            self::assertCount(1, $transport->requests);
        }
    }

    /**
     * @return array<string, array{int, class-string<ApiException>}>
     */
    public static function statuses(): array
    {
        return [
            '401' => [401, AuthenticationException::class],
            '402' => [402, PaymentRequiredException::class],
            '403' => [403, PermissionException::class],
            '404' => [404, NotFoundException::class],
            '409' => [409, ConflictException::class],
            '413' => [413, PayloadTooLargeException::class],
            '422' => [422, ValidationException::class],
            '429' => [429, RateLimitException::class],
            '500' => [500, ServerException::class],
            '418' => [418, ApiException::class],
        ];
    }

    /**
     * @dataProvider statuses
     *
     * @param class-string<ApiException> $class
     */
    public function testMapsStatusesToExceptions(int $status, string $class): void
    {
        $transport = (new FakeTransport())->push($status, ['message' => 'Nope']);

        try {
            $this->client($transport, ['max_retries' => 0])->profiles()->get('user_id', '42');
            self::fail('An exception was expected.');
        } catch (ApiException $e) {
            self::assertSame($class, \get_class($e));
            self::assertSame('Nope', $e->getMessage());
            self::assertSame(['message' => 'Nope'], $e->getPayload());
        }
    }

    public function testPaymentRequiredExceptionReadsTheReason(): void
    {
        $transport = (new FakeTransport())->push(402, ['message' => 'Not in your plan', 'code' => 'feature_unavailable', 'feature' => 'recommendations']);

        try {
            $this->client($transport)->recommendations()->get('popular');
            self::fail('An exception was expected.');
        } catch (PaymentRequiredException $e) {
            self::assertSame('feature_unavailable', $e->getReason());
            self::assertSame('recommendations', $e->getFeature());
        }

        self::assertCount(1, $transport->requests, '402 is not retried.');
    }

    public function testRateLimitExceptionReadsRetryAfter(): void
    {
        $transport = (new FakeTransport())->push(429, ['message' => 'Slow down'], ['Retry-After' => '30']);

        try {
            $this->client($transport, ['max_retries' => 0])->segments()->list();
            self::fail('An exception was expected.');
        } catch (RateLimitException $e) {
            self::assertSame(30, $e->getRetryAfter());
        }
    }

    public function testEncodesPathsAndQueries(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);

        $client->profiles()->get('phone', '+49 151/123');
        self::assertSame('https://cdp.example.com/api/v1/profiles/phone/%2B49%20151%2F123', $transport->last()->getUrl());

        $client->recommendations()->get('viewed_with', ['item' => ['SKU-1', 'SKU 2'], 'exclude' => ['SKU-3'], 'limit' => 4, 'category' => null]);
        self::assertSame(
            'https://cdp.example.com/api/v1/recommendations?type=viewed_with&item%5B%5D=SKU-1&item%5B%5D=SKU%202&exclude%5B%5D=SKU-3&limit=4',
            $transport->last()->getUrl()
        );

        $client->widgets()->get('abc123', ['item' => 'SKU-1']);
        self::assertSame('https://cdp.example.com/api/v1/widgets/abc123?item%5B%5D=SKU-1', $transport->last()->getUrl());

        $client->profiles()->events('user_id', '42', ['before' => new \DateTimeImmutable('2026-10-01T00:00:00+00:00')]);
        self::assertSame('https://cdp.example.com/api/v1/profiles/user_id/42/events?before=2026-10-01T00%3A00%3A00%2B00%3A00', $transport->last()->getUrl());
    }

    public function testAcceptsEveryIdentityTypeOfTheApi(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);

        $client->profiles()->get('viber_id', 'abc+DEF/123==');
        self::assertSame('https://cdp.example.com/api/v1/profiles/viber_id/abc%2BDEF%2F123%3D%3D', $transport->last()->getUrl());

        $client->segments()->addMembers(5, [Identity::viberId('abc'), Identity::telegramChatId(42)]);
        self::assertSame([['type' => 'viber_id', 'value' => 'abc'], ['type' => 'telegram_chat_id', 'value' => '42']], $transport->lastJson()['identities']);

        $spec = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/openapi.json'), true);
        self::assertEqualsCanonicalizing($spec['components']['parameters']['IdentityType']['schema']['enum'], Identity::TYPES);
    }

    public function testRejectsUnknownIdentityTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown identity type "mail"');

        $this->client(new FakeTransport())->profiles()->get('mail', 'anna@example.com');
    }

    public function testEmptyResponsesBecomeEmptyArrays(): void
    {
        $transport = (new FakeTransport())->push(204, null);

        $this->client($transport)->segments()->delete(5);

        self::assertSame('DELETE', $transport->last()->getMethod());
    }

    public function testRejectsNonJsonResponses(): void
    {
        $transport = (new FakeTransport())->raw(200, '<html>Bad gateway</html>');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('not a JSON object');

        $this->client($transport)->segments()->get(1);
    }
}
