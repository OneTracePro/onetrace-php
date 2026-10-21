<?php

declare(strict_types=1);

namespace OneTrace\StoreMcp;

/**
 * MCP server of a store for the OneTrace AI chat bot (store MCP contract v1): checks the platform key, answers
 * initialize, tools/list and tools/call over Streamable HTTP without sessions, checks the customer token for
 * customer tools and the customer's consent for actions. The store only implements the tools it offers:
 *
 *   $server = new Server($platformKey, new CustomerTokens($secret), [
 *       'store_get_customer' => function (array $args, Context $ctx): array { ... },
 *       'store_get_order' => function (array $args, Context $ctx): array { ... },  // $ctx->customerId is confirmed
 *   ], ['name' => 'my-shop', 'verification' => new Verification($codes, $findCustomer, $sendCode)]);
 *   $server->serve(); // or $server->handle($method, $headers, $body) in a framework
 *
 * A handler returns the result object of the contract or throws ToolError; any other exception answers
 * "unavailable" without details. Options: name, version, enabled (tool names the store owner allowed; null — all
 * implemented), verification (Verification for store_start_verification and store_verify).
 */
final class Server
{
    /** @var string */
    private $key;

    /** @var CustomerTokens */
    private $tokens;

    /** @var array<string, callable(array<string, mixed>, Context): array<string, mixed>> */
    private $handlers;

    /** @var array{name: string, version: string, enabled: list<string>|null, verification: Verification|null} */
    private $options;

    /**
     * @param array<string, callable(array<string, mixed>, Context): array<string, mixed>> $handlers
     * @param array<string, mixed> $options
     */
    public function __construct(string $key, CustomerTokens $tokens, array $handlers, array $options = [])
    {
        if (\strlen($key) < 32) {
            throw new \InvalidArgumentException('The platform key must be at least 32 characters.');
        }

        $this->key = $key;
        $this->tokens = $tokens;
        $this->handlers = $handlers;
        $this->options = [
            'name' => Values::string($options['name'] ?? null, 'store'),
            'version' => Values::string($options['version'] ?? null, '1.0.0'),
            'enabled' => isset($options['enabled']) ? array_values(array_filter((array) $options['enabled'], 'is_string')) : null,
            'verification' => ($options['verification'] ?? null) instanceof Verification ? $options['verification'] : null,
        ];
    }

    /**
     * Tools the server offers: implemented, in the contract and allowed by the store owner.
     *
     * @return list<string>
     */
    public function tools(): array
    {
        $tools = [];

        foreach (array_keys(Contract::tools()) as $name) {
            $implemented = \in_array($name, ['store_start_verification', 'store_verify'], true) ? $this->options['verification'] !== null : isset($this->handlers[$name]);

            if ($implemented && ($this->options['enabled'] === null || \in_array($name, $this->options['enabled'], true))) {
                $tools[] = $name;
            }
        }

        return $tools;
    }

    /**
     * @param array<string, string|list<string>> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(string $method, array $headers, string $body): array
    {
        if (strtoupper($method) !== 'POST') {
            return self::response(405, ['error' => 'Use POST.']);
        }

        if (!hash_equals('Bearer ' . $this->key, self::header($headers, 'Authorization'))) {
            return self::response(401, ['error' => 'Unauthorized.']);
        }

        $message = json_decode($body, true);

        if (!\is_array($message) || !isset($message['method']) || !\is_string($message['method'])) {
            return self::response(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
        }

        if (!\array_key_exists('id', $message)) {
            return ['status' => 202, 'headers' => [], 'body' => ''];
        }

        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];

        switch ($message['method']) {
            case 'initialize':
                $result = [
                    'protocolVersion' => Contract::PROTOCOL,
                    'serverInfo' => ['name' => $this->options['name'], 'version' => $this->options['version']],
                    'capabilities' => ['tools' => new \stdClass(), 'experimental' => ['onetrace/store' => ['version' => Contract::VERSION]]],
                ];
                break;
            case 'ping':
                $result = new \stdClass();
                break;
            case 'tools/list':
                $result = ['tools' => array_map([Contract::class, 'definition'], $this->tools())];
                break;
            case 'tools/call':
                $result = $this->call(Values::string($params['name'] ?? null), \is_array($params['arguments'] ?? null) ? $params['arguments'] : [], \is_array($params['_meta'] ?? null) ? $params['_meta'] : []);
                break;
            default:
                return self::response(200, ['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => ['code' => -32601, 'message' => 'Method not found']]);
        }

        return self::response(200, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result]);
    }

    /**
     * Plain PHP: answers the current request and stops.
     */
    public function serve(): void
    {
        $headers = \function_exists('getallheaders') ? (array) getallheaders() : [];

        if (!isset($headers['Authorization']) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = Values::string($_SERVER['HTTP_AUTHORIZATION']);
        }

        $response = $this->handle(Values::string($_SERVER['REQUEST_METHOD'] ?? null, 'GET'), $headers, (string) file_get_contents('php://input'));
        http_response_code($response['status']);

        foreach ($response['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $response['body'];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function call(string $name, array $arguments, array $meta): array
    {
        if (!\in_array($name, $this->tools(), true)) {
            return self::result(['error' => ToolError::NOT_ALLOWED, 'message' => 'This tool is not available.'], true);
        }

        $tool = Contract::tools()[$name];
        $token = \is_string($meta['onetrace/customer_token'] ?? null) ? $meta['onetrace/customer_token'] : null;
        $context = new Context(
            $token !== null ? $this->tokens->verify($token) : null,
            \is_string($meta['onetrace/language'] ?? null) ? $meta['onetrace/language'] : 'en',
            \is_string($meta['onetrace/conversation'] ?? null) ? $meta['onetrace/conversation'] : null,
            ($meta['onetrace/confirmed'] ?? false) === true,
            \is_string($meta['onetrace/idempotency_key'] ?? null) ? $meta['onetrace/idempotency_key'] : null
        );

        try {
            if ($tool['auth'] === 'customer' && $context->customerId === null) {
                throw new ToolError(ToolError::AUTH_REQUIRED, 'Please confirm who you are first.');
            }

            if (!$tool['read_only'] && !$context->confirmed) {
                throw new ToolError(ToolError::NOT_ALLOWED, 'The customer has not confirmed this action.');
            }

            return self::result($this->run($name, $arguments, $context), false);
        } catch (ToolError $e) {
            return self::result(['error' => $e->getErrorCode(), 'message' => $e->getMessage()], true);
        } catch (\Throwable $e) {
            return self::result(['error' => ToolError::UNAVAILABLE, 'message' => 'The store could not answer, try again later.'], true);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function run(string $name, array $arguments, Context $context): array
    {
        $verification = $this->options['verification'];

        if ($name === 'store_start_verification' && $verification !== null) {
            return $verification->start(Values::string($arguments['contact'] ?? null), isset($arguments['order']) ? Values::string($arguments['order']) : null);
        }

        if ($name === 'store_verify' && $verification !== null) {
            $token = $this->tokens->issue($verification->verify(Values::string($arguments['contact'] ?? null), Values::string($arguments['code'] ?? null)));

            return ['customer_token' => $token, 'expires_at' => CustomerTokens::expiresAt($token)];
        }

        return ($this->handlers[$name])($arguments, $context);
    }

    /**
     * @param array<string, mixed> $structured
     *
     * @return array<string, mixed>
     */
    private static function result(array $structured, bool $error): array
    {
        $result = ['content' => [['type' => 'text', 'text' => (string) json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]], 'structuredContent' => $structured];

        return $error ? $result + ['isError' => true] : $result;
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private static function header(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return \is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function response(int $status, array $body): array
    {
        return ['status' => $status, 'headers' => ['Content-Type' => 'application/json'], 'body' => (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }
}
