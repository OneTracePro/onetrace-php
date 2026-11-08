<?php

declare(strict_types=1);

namespace OneTrace\StoreMcp;

/**
 * Customer tokens of the store (contract section 5): "v1.<customer id>.<expires>.<hmac>", signed with a secret only
 * the store knows. The store issues one for a customer signed in on the website (pass it to the tracker with
 * cdp.chatCustomer(token)) and after a confirmation code; the platform keeps it encrypted and sends it back with
 * every call, and only the store can check it.
 *
 * Guest tokens (contract v2, section 10.1): "g1.<email or phone>.<expires>.<hmac>" — a confirmed address without an
 * account, for a guest order. verify() never accepts a guest token, guest() never accepts a customer token.
 */
final class CustomerTokens
{
    private const CUSTOMER = 'v1';
    private const GUEST = 'g1';

    /** @var string */
    private $secret;

    public function __construct(string $secret)
    {
        if (\strlen($secret) < 32) {
            throw new \InvalidArgumentException('The customer token secret must be at least 32 characters.');
        }

        $this->secret = $secret;
    }

    public function issue(string $customerId, int $ttl = 86400): string
    {
        return $this->sign(self::CUSTOMER, $customerId, $ttl);
    }

    /**
     * A token of a guest: the email or phone confirmed with a code, without an account in the store.
     */
    public function issueGuest(string $contact, int $ttl = 86400): string
    {
        return $this->sign(self::GUEST, $contact, $ttl);
    }

    /**
     * The customer id of a valid customer token, null for a guest token or a forged, malformed or expired one.
     */
    public function verify(string $token): ?string
    {
        return $this->read($token, self::CUSTOMER);
    }

    /**
     * The email or phone of a valid guest token, null for a customer token or a forged, malformed or expired one.
     */
    public function guest(string $token): ?string
    {
        return $this->read($token, self::GUEST);
    }

    public static function expiresAt(string $token): ?string
    {
        $parts = explode('.', $token);

        return \count($parts) === 4 && ctype_digit($parts[2]) ? gmdate('Y-m-d\TH:i:s\Z', (int) $parts[2]) : null;
    }

    private function sign(string $kind, string $subject, int $ttl): string
    {
        $payload = $kind . '.' . self::encode($subject) . '.' . (time() + $ttl);

        return $payload . '.' . self::encode(hash_hmac('sha256', $payload, $this->secret, true));
    }

    private function read(string $token, string $kind): ?string
    {
        $parts = explode('.', $token);

        if (\count($parts) !== 4 || $parts[0] !== $kind || !ctype_digit($parts[2])) {
            return null;
        }

        $payload = $parts[0] . '.' . $parts[1] . '.' . $parts[2];

        if (!hash_equals(self::encode(hash_hmac('sha256', $payload, $this->secret, true)), $parts[3]) || (int) $parts[2] < time()) {
            return null;
        }

        $subject = self::decode($parts[1]);

        return $subject === '' ? null : $subject;
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
