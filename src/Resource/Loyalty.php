<?php

declare(strict_types=1);

namespace OneTrace\Resource;

use OneTrace\Page;

/**
 * Loyalty program: balance and history of a member, points at checkout, manual adjustments. Reading needs a secret
 * key with loyalty.read, changes need loyalty.write. A profile is an identity: Identity::email('anna@example.com').
 *
 * Checkout: calculate() shows how many points the cart can take, hold() reserves them for 30 minutes,
 * confirm() binds them to the created order, cancel() returns them. Refunds and cancellations of the order
 * (order_refunded / order_cancelled events) return the redeemed points automatically.
 */
final class Loyalty extends Resource
{
    /**
     * Points for a cart: balance, max_points and max_discount that can be redeemed, earn_points for the order.
     *
     * @param array{type: string, value: string} $profile
     *
     * @return array<string, mixed>
     */
    public function calculate(array $profile, float $amount): array
    {
        return $this->requester->request('POST', '/loyalty/calculate', ['json' => ['profile' => $profile, 'amount' => $amount]]);
    }

    /**
     * The member's account: balance, pending, tier, tier_spend, expiring, expires_at.
     *
     * @return array<string, mixed>
     */
    public function account(string $type, string $value): array
    {
        return $this->requester->request('GET', $this->accountPath($type, $value));
    }

    /**
     * Operations of the member, newest first: earn, cancel, expire, adjust, redeem, refund.
     *
     * @param array<string, mixed> $params limit (1–200, 50 by default), cursor
     */
    public function transactions(string $type, string $value, array $params = []): Page
    {
        return Page::fromResponse($this->requester->request('GET', $this->accountPath($type, $value) . '/transactions', ['query' => $params]));
    }

    /**
     * All operations of the member across pages, newest first.
     *
     * @param array<string, mixed> $params limit
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateTransactions(string $type, string $value, array $params = []): \Generator
    {
        return $this->paginate(function (?string $cursor) use ($type, $value, $params): Page {
            return $this->transactions($type, $value, self::filled($params + ['cursor' => $cursor]));
        });
    }

    /**
     * Enrolls the profile (programs with explicit enrollment); a repeat returns the existing account.
     *
     * @param array{type: string, value: string} $profile
     *
     * @return array<string, mixed>
     */
    public function join(array $profile, ?string $idempotencyKey = null): array
    {
        return $this->requester->request('POST', '/loyalty/members', [
            'json' => ['profile' => $profile],
            'idempotent' => true,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * Reserves points for the cart for 30 minutes; a new hold of the same cart replaces the previous one.
     *
     * @param array{type: string, value: string} $profile
     * @param float|null                         $amount         the cart amount: the points may not exceed the program's share of it
     * @param string|null                        $idempotencyKey a repeat with the same key returns the same hold; generated if null
     *
     * @return array<string, mixed> id, status, points, discount, held_until
     */
    public function hold(array $profile, int $points, string $cartId, ?float $amount = null, ?string $idempotencyKey = null): array
    {
        return $this->requester->request('POST', '/loyalty/redemptions', [
            'json' => self::filled(['profile' => $profile, 'points' => $points, 'cart_id' => $cartId, 'amount' => $amount]),
            'idempotent' => true,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * Confirms the hold with the created order; a repeat with the same order changes nothing.
     *
     * @return array<string, mixed>
     */
    public function confirm(string $redemption, string $orderId): array
    {
        return $this->requester->request('POST', $this->path('/loyalty/redemptions/{id}/confirm', ['id' => $redemption]), [
            'json' => ['order_id' => $orderId],
            'idempotent' => true,
        ]);
    }

    /**
     * Returns the held or redeemed points to the balance.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $redemption): array
    {
        return $this->requester->request('POST', $this->path('/loyalty/redemptions/{id}/cancel', ['id' => $redemption]));
    }

    /**
     * Adds (points > 0) or deducts (points < 0) points with a reason shown in the member's history.
     *
     * @param array{type: string, value: string} $profile
     * @param string|null                        $idempotencyKey a repeat with the same key changes nothing; generated if null
     *
     * @return array<string, mixed> the account after the adjustment
     */
    public function adjust(array $profile, int $points, string $reason, ?string $idempotencyKey = null): array
    {
        return $this->requester->request('POST', '/loyalty/adjustments', [
            'json' => ['profile' => $profile, 'points' => $points, 'reason' => $reason],
            'idempotent' => true,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    private function accountPath(string $type, string $value): string
    {
        return $this->path('/loyalty/profiles/{type}/{value}', $this->identity($type, $value));
    }
}
