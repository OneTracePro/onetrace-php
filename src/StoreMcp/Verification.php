<?php

declare(strict_types=1);

namespace OneTrace\StoreMcp;

/**
 * Customer confirmation with a one-time code (contract sections 4.1 and 5): the store finds the customer by the
 * email or phone (and optionally the order number) and sends the code itself. The answer is the same whether the
 * customer exists or not. Code: 6 digits, 10 minutes, 5 attempts; at most 5 codes an hour for one address.
 *
 * Purpose "order" (contract v2, section 10.1): the code goes to any address, also without an account — sendCode
 * gets an empty customer id for it, and the confirmed address becomes a guest (confirm() without a customer id).
 */
final class Verification
{
    public const PURPOSE_ORDER = 'order';

    private const TTL = 600;
    private const ATTEMPTS = 5;
    private const CODES_PER_HOUR = 5;

    /** @var CodeStore */
    private $store;

    /** @var callable(string, ?string): ?string */
    private $findCustomer;

    /** @var callable(string, string, string, string): void */
    private $sendCode;

    /**
     * @param callable(string $contact, ?string $order): ?string $findCustomer the customer id, null if unknown
     * @param callable(string $customerId, string $contact, string $code, string $channel): void $sendCode channel "email" or "sms"; the customer id is empty for a guest code (purpose "order")
     */
    public function __construct(CodeStore $store, callable $findCustomer, callable $sendCode)
    {
        $this->store = $store;
        $this->findCustomer = $findCustomer;
        $this->sendCode = $sendCode;
    }

    /**
     * Sends a code: without a purpose only to a known customer, with purpose "order" to any address (guest order).
     *
     * @return array<string, mixed>
     */
    public function start(string $contact, ?string $order, ?string $purpose = null): array
    {
        $contact = self::normalize($contact);

        if ($contact === '') {
            throw new ToolError(ToolError::INVALID_INPUT, 'Email or phone is required.');
        }

        if ($purpose !== null && $purpose !== '' && $purpose !== self::PURPOSE_ORDER) {
            throw new ToolError(ToolError::INVALID_INPUT, 'Unknown purpose of the code.');
        }

        $guests = $purpose === self::PURPOSE_ORDER;

        // A guest code makes the address the contact of an order: it must be a real email or phone number.
        if ($guests && !self::valid($contact)) {
            throw new ToolError(ToolError::INVALID_INPUT, 'The email or phone number is not valid.');
        }

        $channel = strpos($contact, '@') !== false ? 'email' : 'sms';
        $limitKey = 'onetrace_mcp_limit_' . sha1($contact);
        $limit = $this->store->get($limitKey) ?? ['count' => 0];

        if (Values::int($limit['count'] ?? null) >= self::CODES_PER_HOUR) {
            throw new ToolError(ToolError::RATE_LIMITED, 'Too many codes, try again later.');
        }

        $this->store->put($limitKey, ['count' => Values::int($limit['count'] ?? null) + 1], 3600);
        $customerId = Values::string(($this->findCustomer)($contact, $order));

        if ($customerId !== '' || $guests) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $this->store->put('onetrace_mcp_code_' . sha1($contact), ['hash' => hash('sha256', $code), 'customer' => $customerId, 'attempts' => 0], self::TTL);
            ($this->sendCode)($customerId, $contact, $code, $channel);
        }

        return ['sent' => true, 'channel' => $channel, 'destination' => self::mask($contact)];
    }

    /**
     * The customer id for a correct code (contract v1). A guest code (purpose "order") is not a customer: use confirm().
     */
    public function verify(string $contact, string $code): string
    {
        $confirmed = $this->confirm($contact, $code);

        if ($confirmed['customer_id'] === null) {
            throw new ToolError(ToolError::INVALID_CODE, 'The code is wrong or expired.');
        }

        return $confirmed['customer_id'];
    }

    /**
     * Checks the code: the customer id, or null for a guest, and the confirmed address (lower-case email or phone
     * digits with +).
     *
     * @return array{customer_id: string|null, contact: string}
     */
    public function confirm(string $contact, string $code): array
    {
        $contact = self::normalize($contact);
        $key = 'onetrace_mcp_code_' . sha1($contact);
        $record = $this->store->get($key);

        if ($record === null || Values::int($record['attempts'] ?? null) >= self::ATTEMPTS) {
            throw new ToolError(ToolError::INVALID_CODE, 'The code is wrong or expired.');
        }

        if (!hash_equals(Values::string($record['hash'] ?? null), hash('sha256', preg_replace('/\D/', '', $code) ?? ''))) {
            $record['attempts'] = Values::int($record['attempts'] ?? null) + 1;
            $this->store->put($key, $record, self::TTL);

            throw new ToolError(ToolError::INVALID_CODE, 'The code is wrong or expired.');
        }

        $this->store->delete($key);
        $customerId = Values::string($record['customer'] ?? null);

        return ['customer_id' => $customerId === '' ? null : $customerId, 'contact' => $contact];
    }

    /**
     * The address as codes and guest tokens keep it: lower-case email, or phone digits with +.
     */
    public static function normalize(string $contact): string
    {
        $contact = strtolower(trim($contact));

        return strpos($contact, '@') !== false ? $contact : (string) preg_replace('/[^\d+]/', '', $contact);
    }

    private static function valid(string $contact): bool
    {
        if (strpos($contact, '@') !== false) {
            return filter_var($contact, FILTER_VALIDATE_EMAIL) !== false;
        }

        return preg_match('/^\+?\d{7,15}$/', $contact) === 1;
    }

    private static function mask(string $contact): string
    {
        if (strpos($contact, '@') !== false) {
            [$local, $domain] = explode('@', $contact, 2);

            return substr($local, 0, 1) . '***@' . $domain;
        }

        return str_repeat('*', max(0, \strlen($contact) - 4)) . substr($contact, -4);
    }
}
