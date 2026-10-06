<?php

declare(strict_types=1);

namespace OneTrace\Resource;

use OneTrace\Http\Requester;

/**
 * Website widgets configured in the admin panel, with their products. Works with a write key or a secret key.
 */
final class Widgets extends Resource
{
    /**
     * get('a1b2c3d4e5f6', ['item' => 'SKU-1']): widget settings, request_id and items. A paused widget returns no items.
     *
     * @param array{item?: string|list<string>, category?: string, exclude?: list<string>, anonymousId?: string} $params
     *
     * @return array<string, mixed>
     */
    public function get(string $uid, array $params = []): array
    {
        $query = $params;

        // The endpoint takes item as a list.
        if (isset($query['item']) && !\is_array($query['item'])) {
            $query['item'] = [$query['item']];
        }

        return $this->requester->request('GET', $this->path('/widgets/{uid}', ['uid' => $uid]), [
            'auth' => Requester::AUTH_WRITE,
            'query' => $query,
        ]);
    }
}
