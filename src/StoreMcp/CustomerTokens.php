<?php

declare(strict_types=1);

namespace OneTrace\StoreMcp;

/**
 * Customer tokens of the store (contract section 5): "v1.<customer id>.<expires>.<hmac>", signed with a secret only
 * the store knows. The store issues one for a customer signed in on the website (pass it to the tracker with
 * cdp.chatCustomer(token)) and after a confirmation code; the platform keeps it encrypted and sends it back with
 * every call, and only the store can check it.
 */
final class CustomerTokens
{
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
        $payload = 'v1.' . self::encode($customerId) . '.' . (time() + $ttl);

        return $payload . '.' . self::encode(hash_hmac('sha256', $payload, $this->secret, true));
    }

    /**
     * The customer id of a valid token, null for a forged, malformed or expired one.
     */
    public function verify(string $token): ?string
    {
        $parts = explode('.', $token);

        if (\count($parts) !== 4 || $parts[0] !== 'v1' || !ctype_digit($parts[2])) {
            return null;
        }

        $payload = $parts[0] . '.' . $parts[1] . '.' . $parts[2];

        if (!hash_equals(self::encode(hash_hmac('sha256', $payload, $this->secret, true)), $parts[3]) || (int) $parts[2] < time()) {
            return null;
        }

        $id = self::decode($parts[1]);

        return $id === '' ? null : $id;
    }

    public static function expiresAt(string $token): ?string
    {
        $parts = explode('.', $token);

        return \count($parts) === 4 && ctype_digit($parts[2]) ? gmdate('Y-m-d\TH:i:s\Z', (int) $parts[2]) : null;
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
