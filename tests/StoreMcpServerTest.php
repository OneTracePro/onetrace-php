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

    /** @var list<array{args: array<string, mixed>, ctx: Context}> */
    private $orders = [];

    /** @var list<array{args: array<string, mixed>, ctx: Context}> */
    private $quotes = [];

    /**
     * A contract v2 store: the v1 tools above and both order tools, codes and action results in one cache.
     */
    private function orderServer(?array $enabled = null, ?ArrayCodeStore $results = null): Server
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
            'store_find_orders' => static function (): array {
                return ['orders' => []];
            },
            'store_quote_order' => function (array $args, Context $ctx): array {
                $this->quotes[] = ['args' => $args, 'ctx' => $ctx];

                return ['items' => [], 'unavailable' => [], 'total' => ['amount' => 100, 'currency' => 'EUR']];
            },
            'store_create_order' => function (array $args, Context $ctx): array {
                $this->orders[] = ['args' => $args, 'ctx' => $ctx];

                return ['order' => (string) (1000 + \count($this->orders)), 'status' => 'pending', 'total' => ['amount' => 100, 'currency' => 'EUR'], 'payment_status' => 'pending', 'payment_url' => 'https://shop.example.com/pay/1'];
            },
        ], ['enabled' => $enabled, 'verification' => $verification, 'idempotency' => $results]);
    }

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

    public function testAnnouncesVersionTwoOnlyWithBothOrderTools(): void
    {
        $v1 = $this->rpc($this->server(), 'initialize')['result'];
        $this->assertSame(['version' => 1], $v1['capabilities']['experimental']['onetrace/store']);
        $this->assertSame(1, $this->server()->version());

        $v2 = $this->orderServer();
        $this->assertSame(['version' => 2], $this->rpc($v2, 'initialize')['result']['capabilities']['experimental']['onetrace/store']);
        $tools = $this->rpc($v2, 'tools/list')['result']['tools'];
        $this->assertSame(['store_start_verification', 'store_verify', 'store_get_customer', 'store_find_orders', 'store_quote_order', 'store_create_order'], array_column($tools, 'name'));
        $this->assertSame(['readOnlyHint' => true], $tools[4]['annotations']);
        $this->assertSame('none', $tools[4]['_meta']['onetrace/auth']);
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => true], $tools[5]['annotations']);
        $this->assertSame('customer', $tools[5]['_meta']['onetrace/auth']);
        $this->assertSame(['items', 'shipping', 'shipping_method'], $tools[5]['inputSchema']['required']);

        // One order tool alone is not version 2 and is not offered.
        $quoteOnly = new Server(self::KEY, new CustomerTokens(self::SECRET), ['store_quote_order' => static function (): array {
            return [];
        }]);
        $this->assertSame(1, $quoteOnly->version());
        $this->assertSame([], $quoteOnly->tools());
        $this->assertSame('not_allowed', $this->call($quoteOnly, 'store_quote_order', ['items' => [['product_id' => '1', 'quantity' => 1]]])['structuredContent']['error']);

        // The owner did not allow the orders: still version 2 by the handlers, the tools are not listed.
        $off = $this->orderServer(['store_get_customer']);
        $this->assertSame(2, $off->version());
        $this->assertSame(['store_get_customer'], $off->tools());
    }

    public function testSendsAnOrderCodeToAnAddressWithoutAnAccountAndIssuesAGuestToken(): void
    {
        $server = $this->orderServer();

        // Without a purpose — contract v1: no code for an unknown address, the same answer.
        $this->assertSame(['sent' => true, 'channel' => 'email', 'destination' => 'n***@example.com'], $this->call($server, 'store_start_verification', ['contact' => 'new@example.com'])['structuredContent']);
        $this->assertSame([], $this->sent);

        $started = $this->call($server, 'store_start_verification', ['contact' => 'New@Example.com', 'purpose' => 'order']);
        $this->assertSame(['sent' => true, 'channel' => 'email', 'destination' => 'n***@example.com'], $started['structuredContent']);
        [$customer, $code, $channel] = $this->sent[0];
        $this->assertSame(['', 'email'], [$customer, $channel]);

        $verified = $this->call($server, 'store_verify', ['contact' => 'new@example.com', 'code' => $code])['structuredContent'];
        $tokens = new CustomerTokens(self::SECRET);
        $this->assertNull($tokens->verify($verified['customer_token']));
        $this->assertSame('new@example.com', $tokens->guest($verified['customer_token']));
        $this->assertNotEmpty($verified['expires_at']);

        $meta = ['onetrace/customer_token' => $verified['customer_token']];
        $this->assertSame(['id' => null, 'email' => 'new@example.com'], $this->call($server, 'store_get_customer', [], $meta)['structuredContent']);
        $this->assertSame('auth_required', $this->call($server, 'store_find_orders', [], $meta)['structuredContent']['error']);

        // A known customer with purpose "order" gets a customer token.
        $this->call($server, 'store_start_verification', ['contact' => 'anna@example.com', 'purpose' => 'order']);
        [$customer, $code] = $this->sent[1];
        $this->assertSame('42', $customer);
        $token = $this->call($server, 'store_verify', ['contact' => 'anna@example.com', 'code' => $code])['structuredContent']['customer_token'];
        $this->assertSame('42', $tokens->verify($token));

        // A phone guest.
        $this->call($server, 'store_start_verification', ['contact' => '+49 (151) 234-5678', 'purpose' => 'order']);
        [, $code, $channel] = $this->sent[2];
        $this->assertSame('sms', $channel);
        $phone = $this->call($server, 'store_verify', ['contact' => '+491512345678', 'code' => $code])['structuredContent']['customer_token'];
        $this->assertSame(['id' => null, 'phone' => '+491512345678'], $this->call($server, 'store_get_customer', [], ['onetrace/customer_token' => $phone])['structuredContent']);

        $this->assertSame('invalid_input', $this->call($server, 'store_start_verification', ['contact' => 'not-an-email@', 'purpose' => 'order'])['structuredContent']['error']);
        $this->assertSame('invalid_input', $this->call($server, 'store_start_verification', ['contact' => 'new@example.com', 'purpose' => 'spam'])['structuredContent']['error']);
    }

    public function testOrderCodesKeepTheLimitsAndAreOffOnlyWithoutTheOrderTool(): void
    {
        $server = $this->orderServer();

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->call($server, 'store_start_verification', ['contact' => 'new@example.com', 'purpose' => 'order'])['structuredContent']['sent']);
        }

        $this->assertSame('rate_limited', $this->call($server, 'store_start_verification', ['contact' => 'new@example.com', 'purpose' => 'order'])['structuredContent']['error']);
        $this->assertCount(5, $this->sent);

        $code = $this->sent[4][1];
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('invalid_code', $this->call($server, 'store_verify', ['contact' => 'new@example.com', 'code' => $wrong])['structuredContent']['error']);
        }

        $this->assertSame('invalid_code', $this->call($server, 'store_verify', ['contact' => 'new@example.com', 'code' => $code])['structuredContent']['error']);

        // A v1 store ignores the purpose: no code to an unknown address.
        $this->sent = [];
        $this->call($this->server(), 'store_start_verification', ['contact' => 'new@example.com', 'purpose' => 'order']);
        $this->assertSame([], $this->sent);

        // The owner turned the orders off: no guest codes either.
        $this->call($this->orderServer(['store_start_verification', 'store_verify']), 'store_start_verification', ['contact' => 'new@example.com', 'purpose' => 'order']);
        $this->assertSame([], $this->sent);
    }

    public function testChecksAndNormalizesTheQuoteInput(): void
    {
        $server = $this->orderServer();

        $quote = $this->call($server, 'store_quote_order', [
            'items' => [['product_id' => 'sku-1', 'quantity' => 2], ['product_id' => 77, 'quantity' => '1'], ['product_id' => 'sku-1', 'quantity' => 3.0]],
            'shipping' => ['country' => 'de', 'city' => ' Berlin ', 'postcode' => '', 'extra' => 'x'],
        ], ['onetrace/source' => 'assistant', 'onetrace/assistant' => 'ChatGPT']);
        $this->assertFalse($quote['isError'] ?? false);
        $this->assertSame([
            'items' => [['product_id' => 'sku-1', 'quantity' => 5], ['product_id' => '77', 'quantity' => 1]],
            'shipping' => ['country' => 'DE', 'city' => 'Berlin'],
            'shipping_method' => null,
        ], $this->quotes[0]['args']);
        $this->assertTrue($this->quotes[0]['ctx']->fromAssistant());
        $this->assertSame('chatgpt', $this->quotes[0]['ctx']->assistant);

        $this->call($server, 'store_quote_order', ['items' => [['product_id' => '1', 'quantity' => 1]]]);
        $this->assertSame([], $this->quotes[1]['args']['shipping']);

        $invalid = [
            'no items' => [],
            'empty items' => ['items' => []],
            'items as an object' => ['items' => ['a' => ['product_id' => '1', 'quantity' => 1]]],
            'no product id' => ['items' => [['quantity' => 1]]],
            'zero quantity' => ['items' => [['product_id' => '1', 'quantity' => 0]]],
            'quantity over 99' => ['items' => [['product_id' => '1', 'quantity' => 100]]],
            'fractional quantity' => ['items' => [['product_id' => '1', 'quantity' => 1.5]]],
            'over 99 together' => ['items' => [['product_id' => '1', 'quantity' => 60], ['product_id' => '1', 'quantity' => 40]]],
            '21 lines' => ['items' => array_map(static function (int $i): array {
                return ['product_id' => (string) $i, 'quantity' => 1];
            }, range(1, 21))],
            'bad country' => ['items' => [['product_id' => '1', 'quantity' => 1]], 'shipping' => ['country' => 'Germany']],
            'shipping as a string' => ['items' => [['product_id' => '1', 'quantity' => 1]], 'shipping' => 'Berlin'],
            'shipping field as an object' => ['items' => [['product_id' => '1', 'quantity' => 1]], 'shipping' => ['city' => ['Berlin']]],
        ];

        foreach ($invalid as $case => $arguments) {
            $answer = $this->call($server, 'store_quote_order', $arguments);
            $this->assertTrue($answer['isError'] ?? false, $case);
            $this->assertSame('invalid_input', $answer['structuredContent']['error'], $case);
            $this->assertNotSame('', $answer['structuredContent']['message'], $case);
        }

        $this->assertCount(2, $this->quotes);
    }

    public function testCreatesAnOrderForAGuestOnlyConfirmedAndOncePerIdempotencyKey(): void
    {
        $server = $this->orderServer(null, new ArrayCodeStore());
        $guest = (new CustomerTokens(self::SECRET))->issueGuest('new@example.com');
        $order = [
            'items' => [['product_id' => 'sku-1', 'quantity' => 1]],
            'shipping' => ['first_name' => 'Ann', 'last_name' => 'Lee', 'phone' => '+491512345678', 'country' => 'DE', 'city' => 'Berlin', 'postcode' => '10115', 'address' => 'Unter den Linden 1'],
            'shipping_method' => 'dhl',
            'comment' => 'Ring twice',
        ];
        $meta = ['onetrace/customer_token' => $guest, 'onetrace/confirmed' => true, 'onetrace/idempotency_key' => 'k-1', 'onetrace/source' => 'assistant', 'onetrace/assistant' => 'claude', 'onetrace/conversation' => 'assistant:abc'];

        $this->assertSame('auth_required', $this->call($server, 'store_create_order', $order, ['onetrace/confirmed' => true, 'onetrace/idempotency_key' => 'k-1'])['structuredContent']['error']);
        $this->assertSame('not_allowed', $this->call($server, 'store_create_order', $order, ['onetrace/confirmed' => false] + $meta)['structuredContent']['error']);
        $this->assertSame('not_allowed', $this->call($server, 'store_create_order', $order, array_diff_key($meta, ['onetrace/idempotency_key' => true]))['structuredContent']['error']);
        $this->assertSame('invalid_input', $this->call($server, 'store_create_order', ['shipping_method' => null] + $order, $meta)['structuredContent']['error']);
        $this->assertSame('invalid_input', $this->call($server, 'store_create_order', ['shipping' => ['country' => 'DE', 'city' => 'Berlin']] + $order, $meta)['structuredContent']['error']);
        $this->assertSame('invalid_input', $this->call($server, 'store_create_order', ['comment' => str_repeat('я', 1001)] + $order, $meta)['structuredContent']['error']);
        $this->assertSame([], $this->orders);

        $created = $this->call($server, 'store_create_order', $order, $meta)['structuredContent'];
        $this->assertSame(['order' => '1001', 'status' => 'pending', 'total' => ['amount' => 100, 'currency' => 'EUR'], 'payment_status' => 'pending', 'payment_url' => 'https://shop.example.com/pay/1'], $created);

        $ctx = $this->orders[0]['ctx'];
        $this->assertNull($ctx->customerId);
        $this->assertTrue($ctx->isGuest());
        $this->assertSame('new@example.com', $ctx->guestEmail());
        $this->assertNull($ctx->guestPhone());
        $this->assertSame(['assistant', 'claude', 'assistant:abc', 'k-1'], [$ctx->source, $ctx->assistant, $ctx->conversation, $ctx->idempotencyKey]);
        $this->assertSame(['items' => $order['items'], 'shipping' => $order['shipping'], 'shipping_method' => 'dhl', 'payment_method' => null, 'comment' => 'Ring twice'], $this->orders[0]['args']);

        // The same key — the same order, the handler does not run again; another key — a new order.
        $this->assertSame($created, $this->call($server, 'store_create_order', $order, $meta)['structuredContent']);
        $this->assertCount(1, $this->orders);
        $this->assertSame('1002', $this->call($server, 'store_create_order', $order, ['onetrace/idempotency_key' => 'k-2'] + $meta)['structuredContent']['order']);

        // A customer with the same key does not get the guest's order.
        $customer = ['onetrace/customer_token' => (new CustomerTokens(self::SECRET))->issue('42')] + $meta;
        $this->assertSame('1003', $this->call($server, 'store_create_order', $order, $customer)['structuredContent']['order']);
        $this->assertSame('42', $this->orders[2]['ctx']->customerId);
        $this->assertFalse($this->orders[2]['ctx']->isGuest());
    }

    public function testDoesNotKeepFailedActionsAndHoldsARunningOne(): void
    {
        $results = new ArrayCodeStore();
        $attempts = 0;
        $server = new Server(self::KEY, new CustomerTokens(self::SECRET), [
            'store_quote_order' => static function (): array {
                return [];
            },
            'store_create_order' => static function () use (&$attempts): array {
                if (++$attempts === 1) {
                    throw new ToolError(ToolError::INVALID_INPUT, 'The price has changed.');
                }

                return ['order' => '1001'];
            },
        ], ['idempotency' => $results]);
        $order = ['items' => [['product_id' => '1', 'quantity' => 1]], 'shipping' => ['first_name' => 'A', 'last_name' => 'B', 'country' => 'DE', 'city' => 'Berlin', 'address' => 'Main 1'], 'shipping_method' => 'dhl'];
        $meta = ['onetrace/customer_token' => (new CustomerTokens(self::SECRET))->issue('42'), 'onetrace/confirmed' => true, 'onetrace/idempotency_key' => 'k-1'];

        $this->assertSame('invalid_input', $this->call($server, 'store_create_order', $order, $meta)['structuredContent']['error']);
        $this->assertSame('1001', $this->call($server, 'store_create_order', $order, $meta)['structuredContent']['order']);
        $this->assertSame(2, $attempts);

        // A repeat while the first call still runs (another request) is not run twice.
        $results->put('onetrace_mcp_action_' . sha1('store_create_order|customer:42|k-2'), ['running' => true], 60);
        $this->assertSame('unavailable', $this->call($server, 'store_create_order', $order, ['onetrace/idempotency_key' => 'k-2'] + $meta)['structuredContent']['error']);
        $this->assertSame(2, $attempts);
    }

    public function testContextOfTheChatBotAndUnknownAssistants(): void
    {
        $tokens = new CustomerTokens(self::SECRET);

        $chat = Context::fromMeta(['onetrace/customer_token' => $tokens->issue('42'), 'onetrace/language' => 'ru'], $tokens);
        $this->assertSame(['chat', null, 'ru', '42', null], [$chat->source, $chat->assistant, $chat->language, $chat->customerId, $chat->guestContact]);
        $this->assertFalse($chat->fromAssistant());

        $unknown = Context::fromMeta(['onetrace/source' => 'assistant', 'onetrace/assistant' => 'some-new-bot'], $tokens);
        $this->assertSame(['assistant', 'other', 'en'], [$unknown->source, $unknown->assistant, $unknown->language]);
        $this->assertSame('chat', Context::fromMeta(['onetrace/source' => 'elsewhere', 'onetrace/assistant' => 'claude'], $tokens)->source);
        $this->assertNull(Context::fromMeta(['onetrace/source' => 'elsewhere', 'onetrace/assistant' => 'claude'], $tokens)->assistant);

        // v1 constructor calls keep working.
        $this->assertSame('chat', (new Context('42', 'en', null, false, null))->source);
    }

    public function testGuestTokensAreNotCustomerTokens(): void
    {
        $tokens = new CustomerTokens(self::SECRET);
        $guest = $tokens->issueGuest('new@example.com');

        $this->assertNull($tokens->verify($guest));
        $this->assertSame('new@example.com', $tokens->guest($guest));
        $this->assertNull($tokens->guest($tokens->issue('42')));
        $this->assertNull($tokens->guest($tokens->issueGuest('new@example.com', -10)));
        $this->assertNull($tokens->verify('v1' . substr($guest, 2)));
        $this->assertNull((new CustomerTokens(str_repeat('x', 40)))->guest($guest));
        $this->assertNotNull(CustomerTokens::expiresAt($guest));

        // A guest token does not open the v1 customer tools of a v1 store either.
        $this->assertSame('auth_required', $this->call($this->server(), 'store_get_order', ['order' => '1001'], ['onetrace/customer_token' => $guest])['structuredContent']['error']);
        $this->assertSame('auth_required', $this->call($this->server(), 'store_get_customer', [], ['onetrace/customer_token' => $guest])['structuredContent']['error']);
    }
}
