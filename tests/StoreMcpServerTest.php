<?php

declare(strict_types=1);

namespace OneTrace\Tests;

use OneTrace\StoreMcp\ArrayCodeStore;
use OneTrace\StoreMcp\Context;
use OneTrace\StoreMcp\CustomerTokens;
use OneTrace\StoreMcp\Server;
use OneTrace\StoreMcp\ToolError;
use OneTrace\StoreMcp\Verification;
use PHPUnit\Framework\TestCase;

final class StoreMcpServerTest extends TestCase
{
    private const KEY = 'platform-key-0123456789abcdef0123456789';
    private const SECRET = 'customer-token-secret-0123456789abcdef';

    /** @var list<array{string, string, string}> */
    private $sent = [];

    /** @var list<array<string, mixed>> */
    private $cancelled = [];

    private function server(?array $enabled = null): Server
    {
        $codes = new ArrayCodeStore();
        $verification = new Verification($codes, static function (string $contact): ?string {
            return $contact === 'anna@example.com' ? '42' : null;
        }, function (string $customerId, string $contact, string $code, string $channel): void {
            $this->sent[] = [$customerId, $code, $channel];
        });

        return new Server(self::KEY, new CustomerTokens(self::SECRET), [
            'store_get_customer' => static function (array $args, Context $ctx): array {
                return ['id' => $ctx->customerId, 'first_name' => 'Anna'];
            },
            'store_get_order' => static function (array $args, Context $ctx): array {
                if ($ctx->customerId !== '42' || $args['order'] !== '1001') {
                    throw new ToolError(ToolError::NOT_FOUND, 'No such order.');
                }

                return ['order' => '1001', 'status' => 'shipped', 'status_label' => $ctx->language === 'ru' ? 'Отправлен' : 'Shipped'];
            },
            'store_cancel_order' => function (array $args, Context $ctx): array {
                $this->cancelled[] = ['order' => $args['order'], 'key' => $ctx->idempotencyKey];

                return ['order' => $args['order'], 'status' => 'cancelled', 'message' => 'Cancelled'];
            },
            'store_check_availability' => static function (): array {
                throw new \RuntimeException('database is down: secret details');
            },
        ], ['name' => 'test-shop', 'version' => '2.0.0', 'enabled' => $enabled, 'verification' => $verification]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(Server $server, string $method, array $params = [], string $key = self::KEY): array
    {
        $response = $server->handle('POST', ['authorization' => 'Bearer ' . $key], (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]));
        $this->assertSame(200, $response['status']);

        return json_decode($response['body'], true);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function call(Server $server, string $tool, array $arguments = [], array $meta = []): array
    {
        return $this->rpc($server, 'tools/call', ['name' => $tool, 'arguments' => $arguments, '_meta' => $meta])['result'];
    }

    public function testDeclaresTheContractAndListsOnlyImplementedAndAllowedTools(): void
    {
        $init = $this->rpc($this->server(), 'initialize', ['protocolVersion' => '2025-06-18'])['result'];
        $this->assertSame(['version' => 1], $init['capabilities']['experimental']['onetrace/store']);
        $this->assertSame(['name' => 'test-shop', 'version' => '2.0.0'], $init['serverInfo']);

        $tools = $this->rpc($this->server(), 'tools/list')['result']['tools'];
        $this->assertSame(['store_start_verification', 'store_verify', 'store_get_customer', 'store_get_order', 'store_check_availability', 'store_cancel_order'], array_column($tools, 'name'));
        $this->assertSame('customer', $tools[3]['_meta']['onetrace/auth']);
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => true], $tools[5]['annotations']);

        // Действия владелец магазина не разрешил.
        $allowed = $this->rpc($this->server(['store_get_order']), 'tools/list')['result']['tools'];
        $this->assertSame(['store_get_order'], array_column($allowed, 'name'));
        $this->assertSame('not_allowed', $this->call($this->server(['store_get_order']), 'store_cancel_order', ['order' => '1001'])['structuredContent']['error']);
    }

    public function testRejectsAWrongPlatformKeyAndAcknowledgesNotifications(): void
    {
        $server = $this->server();

        $this->assertSame(401, $server->handle('POST', ['Authorization' => 'Bearer wrong'], '{}')['status']);
        $this->assertSame(202, $server->handle('POST', ['Authorization' => 'Bearer ' . self::KEY], '{"jsonrpc":"2.0","method":"notifications/initialized"}')['status']);
    }

    public function testConfirmsTheCustomerWithACodeAndChecksTheTokenOnCustomerTools(): void
    {
        $server = $this->server();

        $this->assertSame('auth_required', $this->call($server, 'store_get_order', ['order' => '1001'])['structuredContent']['error']);

        $unknown = $this->call($server, 'store_start_verification', ['contact' => 'nobody@example.com']);
        $this->assertSame(['sent' => true, 'channel' => 'email', 'destination' => 'n***@example.com'], $unknown['structuredContent']);
        $this->assertSame([], $this->sent);

        $this->call($server, 'store_start_verification', ['contact' => 'Anna@Example.com ']);
        [$customer, $code, $channel] = $this->sent[0];
        $this->assertSame(['42', 'email'], [$customer, $channel]);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $this->assertSame('invalid_code', $this->call($server, 'store_verify', ['contact' => 'anna@example.com', 'code' => '000000' === $code ? '111111' : '000000'])['structuredContent']['error']);
        $verified = $this->call($server, 'store_verify', ['contact' => 'anna@example.com', 'code' => $code])['structuredContent'];
        $this->assertNotEmpty($verified['expires_at']);

        $order = $this->call($server, 'store_get_order', ['order' => '1001'], ['onetrace/customer_token' => $verified['customer_token'], 'onetrace/language' => 'ru']);
        $this->assertSame(['order' => '1001', 'status' => 'shipped', 'status_label' => 'Отправлен'], $order['structuredContent']);
        $this->assertJsonStringEqualsJsonString((string) json_encode($order['structuredContent']), $order['content'][0]['text']);

        $forged = (new CustomerTokens(str_repeat('x', 40)))->issue('42');
        $this->assertSame('auth_required', $this->call($server, 'store_get_order', ['order' => '1001'], ['onetrace/customer_token' => $forged])['structuredContent']['error']);
        $this->assertSame('not_found', $this->call($server, 'store_get_order', ['order' => '2002'], ['onetrace/customer_token' => $verified['customer_token']])['structuredContent']['error']);
    }

    public function testRunsActionsOnlyWithTheCustomerConsentAndHidesInternalErrors(): void
    {
        $server = $this->server();
        $token = (new CustomerTokens(self::SECRET))->issue('42');

        $this->assertSame('not_allowed', $this->call($server, 'store_cancel_order', ['order' => '1001'], ['onetrace/customer_token' => $token])['structuredContent']['error']);
        $this->assertSame([], $this->cancelled);

        $done = $this->call($server, 'store_cancel_order', ['order' => '1001'], ['onetrace/customer_token' => $token, 'onetrace/confirmed' => true, 'onetrace/idempotency_key' => 'k-1']);
        $this->assertSame('cancelled', $done['structuredContent']['status']);
        $this->assertSame([['order' => '1001', 'key' => 'k-1']], $this->cancelled);

        $failed = $this->call($server, 'store_check_availability', ['query' => 'shoes']);
        $this->assertTrue($failed['isError']);
        $this->assertSame('unavailable', $failed['structuredContent']['error']);
        $this->assertStringNotContainsString('secret details', $failed['content'][0]['text']);
    }

    public function testCustomerTokensExpireAndCannotBeForged(): void
    {
        $tokens = new CustomerTokens(self::SECRET);

        $this->assertSame('42', $tokens->verify($tokens->issue('42')));
        $this->assertNull($tokens->verify($tokens->issue('42', -10)));
        $this->assertNull($tokens->verify(str_replace('.v', '.x', $tokens->issue('42')) . 'x'));
        $this->assertNull($tokens->verify('v1.NDI.9999999999.bad'));
    }
}
