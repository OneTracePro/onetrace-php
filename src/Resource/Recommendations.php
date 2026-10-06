<?php

declare(strict_types=1);

namespace OneTrace\Resource;

use OneTrace\Http\Requester;

/**
 * Product recommendations, for example for emails or server-side rendering. Works with a write key or a secret key.
 */
final class Recommendations extends Resource
{
    public const TYPES = ['personal', 'popular', 'trending', 'viewed_with', 'bought_with', 'similar', 'recently_viewed'];

    /**
     * get('viewed_with', ['item' => 'SKU-1', 'limit' => 4]) or get('personal', ['anonymousId' => $cookie]).
     *
     * @param string $type one of TYPES
     * @param array{
     *     limit?: int,
     *     item?: string|list<string>,
     *     category?: string,
     *     exclude?: list<string>,
     *     anonymousId?: string,
     *     image_width?: int,
     * } $params item: the product of the page or the products of the cart
     *
     * @return array<string, mixed> type, request_id, items (product cards)
     */
    public function get(string $type, array $params = []): array
    {
        return $this->requester->request('GET', '/recommendations', [
            'auth' => Requester::AUTH_WRITE,
            'query' => ['type' => $type] + $params,
        ]);
    }
}
