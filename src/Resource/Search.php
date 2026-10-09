<?php

declare(strict_types=1);

namespace OneTrace\Resource;

use OneTrace\Http\Requester;

/**
 * Product search for websites: results with facets and suggestions while typing. Works with a write key or a
 * secret key; the plan of the project must include product search.
 */
final class Search extends Resource
{
    /**
     * products('linen dress', ['per_page' => 24, 'anonymousId' => $visitor]): items, total, page, per_page, relaxed,
     * personalized and facets (categories, brands, price). anonymousId — the tracker visitor id, for the order by
     * the visitor's interests; language — the visitor's language ("ru", "de-AT"): names and links of the catalog
     * translations.
     *
     * @param array{category?: string, brand?: string|list<string>, price_min?: float, price_max?: float, sort?: string, page?: int, per_page?: int, all?: bool, anonymousId?: string, image_width?: int, language?: string} $params
     *
     * @return array<string, mixed>
     */
    public function products(string $query, array $params = []): array
    {
        $params = ['q' => $query] + $params;

        // The endpoint takes brand as a list and all as 0/1.
        if (isset($params['brand']) && !\is_array($params['brand'])) {
            $params['brand'] = [$params['brand']];
        }

        if (isset($params['all'])) {
            $params['all'] = $params['all'] ? 1 : 0;
        }

        return $this->requester->request('GET', '/search', [
            'auth' => Requester::AUTH_WRITE,
            'query' => $params,
        ]);
    }

    /**
     * suggest('lin'): products by the beginning of words, categories by name and popular queries of the website.
     *
     * @param array{limit?: int, image_width?: int, language?: string} $params
     *
     * @return array{products: list<array<string, mixed>>, categories: list<array{id: string, name: string}>, queries: list<string>}
     */
    public function suggest(string $query, array $params = []): array
    {
        /** @var array{products: list<array<string, mixed>>, categories: list<array{id: string, name: string}>, queries: list<string>} $result */
        $result = $this->requester->request('GET', '/search/suggest', [
            'auth' => Requester::AUTH_WRITE,
            'query' => ['q' => $query] + $params,
        ]);

        return $result;
    }
}
