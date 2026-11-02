<?php

declare(strict_types=1);

namespace OneTrace\Tests;

use OneTrace\Client;
use OneTrace\Exception\ConfigurationException;
use OneTrace\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function addresses(): array
    {
        return [
            'domain' => ['https://cdp.onetrace.pro', 'https://cdp.onetrace.pro/api/v1'],
            'trailing slash' => ['https://cdp.onetrace.pro/', 'https://cdp.onetrace.pro/api/v1'],
            'with api path' => ['https://crm.brand.example/api/v1/', 'https://crm.brand.example/api/v1'],
            'local http' => ['http://localhost:8180', 'http://localhost:8180/api/v1'],
        ];
    }

    /**
     * @dataProvider addresses
     */
    public function testNormalizesTheAddress(string $given, string $expected): void
    {
        self::assertSame($expected, (new Client($given, ['write_key' => 'cdp_wk_x', 'transport' => new FakeTransport()]))->getBaseUrl());
    }

    public function testRejectsAnAddressWithoutScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Client('cdp.onetrace.pro', ['write_key' => 'cdp_wk_x']);
    }

    public function testNeedsAKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"write_key", "secret_key"');

        new Client('https://cdp.onetrace.pro', ['write_key' => '', 'secret_key' => null]);
    }

    public function testManagementMethodsNeedTheSecretKey(): void
    {
        $transport = new FakeTransport();
        $client = new Client('https://cdp.onetrace.pro', ['write_key' => 'cdp_wk_x', 'transport' => $transport]);

        $client->events()->track(['userId' => '42', 'event' => 'x']);
        self::assertSame('Bearer cdp_wk_x', $transport->last()->getHeader('Authorization'));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('secret key');

        $client->segments()->list();
    }

    public function testEventsFallBackToTheSecretKey(): void
    {
        $transport = new FakeTransport();
        $client = new Client('https://cdp.onetrace.pro', ['secret_key' => ' cdp_sk_x ', 'transport' => $transport]);

        $client->events()->track(['userId' => '42', 'event' => 'x']);

        self::assertSame('Bearer cdp_sk_x', $transport->last()->getHeader('Authorization'));
    }

    public function testEventsPreferTheSecretKeyWithoutSigning(): void
    {
        $transport = new FakeTransport();
        $client = new Client('https://cdp.onetrace.pro', ['write_key' => 'cdp_wk_x', 'secret_key' => 'cdp_sk_x', 'identity_key' => 'cdp_ik_test', 'transport' => $transport]);

        $client->events()->identify(['userId' => 42, 'traits' => ['email' => 'anna@example.com']]);

        self::assertSame('Bearer cdp_sk_x', $transport->last()->getHeader('Authorization'));
        self::assertArrayNotHasKey('userHash', $transport->lastJson());

        $client->recommendations()->get('popular');
        self::assertSame('Bearer cdp_wk_x', $transport->last()->getHeader('Authorization'));
    }

    /**
     * The same HMAC as IdentityVerification::hash() of the platform: hash_hmac('sha256', $userId, $key).
     */
    public function testSignsUserIds(): void
    {
        $client = new Client('https://cdp.onetrace.pro', ['write_key' => 'cdp_wk_x', 'identity_key' => ' cdp_ik_test ', 'transport' => new FakeTransport()]);

        self::assertSame('e3cbe19990e207883f093a7292576b74f55c9d625d2dcfa46ac4999df35607c9', $client->userHash('42'));
        self::assertSame($client->userHash('42'), $client->userHash(42));
        self::assertNull($client->userHash(''));
        self::assertNull((new Client('https://cdp.onetrace.pro', ['write_key' => 'cdp_wk_x', 'identity_key' => '']))->userHash('42'));
    }

    public function testSignsEventsAndSubscriptionsSentWithTheWriteKey(): void
    {
        $transport = (new FakeTransport())->push(202)->push(202)->push(201);
        $client = new Client('https://cdp.onetrace.pro', ['write_key' => 'cdp_wk_x', 'identity_key' => 'cdp_ik_test', 'transport' => $transport]);
        $hash = 'e3cbe19990e207883f093a7292576b74f55c9d625d2dcfa46ac4999df35607c9';

        $client->events()->track(['userId' => 42, 'event' => 'x']);
        self::assertSame('Bearer cdp_wk_x', $transport->last()->getHeader('Authorization'));
        self::assertSame($hash, $transport->lastJson()['userHash']);

        $client->events()->batch([
            ['type' => 'alias', 'userId' => '42', 'previousId' => 'old'],
            ['type' => 'page', 'anonymousId' => 'a-1', 'name' => 'Home'],
            ['type' => 'identify', 'userId' => '7', 'userHash' => 'given'],
        ]);
        $batch = $transport->lastJson()['batch'];
        self::assertSame($hash, $batch[0]['userHash']);
        self::assertArrayNotHasKey('userHash', $batch[1]);
        self::assertSame('given', $batch[2]['userHash']);

        $client->push()->subscribe(['endpoint' => 'https://fcm.googleapis.com/fcm/send/x'], ['userId' => '42', 'anonymousId' => 'a-1']);
        self::assertSame($hash, $transport->lastJson()['userHash']);
    }

    public function testRejectsANonStringIdentityKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('identity key');

        new Client('https://cdp.onetrace.pro', ['write_key' => 'cdp_wk_x', 'identity_key' => 42]);
    }

    public function testSendsTheUserAgentAndJsonHeaders(): void
    {
        $transport = new FakeTransport();
        $client = new Client('https://cdp.onetrace.pro', ['secret_key' => 'cdp_sk_x', 'transport' => $transport, 'user_agent' => 'my-shop/2.1']);

        $client->products()->upsert([['id' => 'SKU-1', 'name' => 'Ботинки']]);
        $request = $transport->last();

        self::assertSame('onetrace-php/' . Client::VERSION . ' PHP/' . PHP_VERSION . ' my-shop/2.1', $request->getHeader('User-Agent'));
        self::assertSame('application/json', $request->getHeader('Content-Type'));
        self::assertSame('application/json', $request->getHeader('Accept'));
        self::assertSame('en', $request->getHeader('Accept-Language'));
        self::assertSame('{"items":[{"id":"SKU-1","name":"Ботинки"}]}', $request->getBody());
    }

    public function testAsksForErrorMessagesInTheGivenLanguage(): void
    {
        $transport = new FakeTransport();
        $client = new Client('https://cdp.onetrace.pro', ['secret_key' => 'cdp_sk_x', 'transport' => $transport, 'language' => 'ru']);

        $client->segments()->list();

        self::assertSame('ru', $transport->last()->getHeader('Accept-Language'));
    }

    public function testPsr18ClientNeedsFactories(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('request_factory');

        new Client('https://cdp.onetrace.pro', ['write_key' => 'cdp_wk_x', 'http_client' => new Support\EchoPsr18Client()]);
    }
}
