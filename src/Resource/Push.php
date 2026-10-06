<?php

declare(strict_types=1);

namespace OneTrace\Resource;

use OneTrace\Http\Requester;

/**
 * Web Push subscriptions collected outside of the JS tracker (for example in a native wrapper).
 * Works with a write key or a secret key.
 */
final class Push extends Resource
{
    /**
     * The VAPID public key for PushManager.subscribe(); 404 when the Web Push channel is not configured.
     *
     * @return array<string, mixed> public_key
     */
    public function config(): array
    {
        return $this->requester->request('GET', '/push/config', ['auth' => Requester::AUTH_WRITE]);
    }

    /**
     * Stores a browser subscription (PushSubscription.toJSON()) and links it to the visitor.
     *
     * @param array<string, mixed>                                                 $subscription endpoint and keys
     * @param array{anonymousId?: string, userId?: string, publicKey?: string} $params
     *
     * @return array<string, mixed> subscription_id
     */
    public function subscribe(array $subscription, array $params = []): array
    {
        return $this->requester->request('POST', '/push/subscriptions', [
            'auth' => Requester::AUTH_WRITE,
            'json' => ['subscription' => $subscription] + $params,
        ]);
    }

    /**
     * Forgets a subscription by its endpoint.
     *
     * @return array<string, mixed> deleted
     */
    public function unsubscribe(string $endpoint): array
    {
        return $this->requester->request('DELETE', '/push/subscriptions', [
            'auth' => Requester::AUTH_WRITE,
            'json' => ['endpoint' => $endpoint],
        ]);
    }
}
