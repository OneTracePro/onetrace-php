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
     * personalized and facets (categories, brands, price, attributes). anonymousId — the tracker visitor id, for the
     * order by the visitor's interests; language — the visitor's language ("ru", "de-AT"): names and links of the
     * catalog translations.
     *
     * The response also says how the query was understood: corrected — the results are for this corrected query
     * (a typo), suggestion — "did you mean" when nothing was found or only some of the words matched; filters — the price, brand and
     * characteristics recognized in the text ("red dress under 50" → price_max, color), already applied; learned —
     * the order of the first results follows what visitors clicked and bought for this query; semantic — the results
     * include products found by meaning; redirect — the address of a search rule. correct: false — search exactly
     * what was typed; ignore — what not to recognize in the text (price, brand, characteristic keys); attr —
     * characteristic filters (['season' => 'winter', 'rim_diameter' => [16, 17]]).
     *
     * Over the monthly search limit of the plan the API answers 402 with PaymentRequiredException::getReason()
     * "quota_exceeded": use the search of your website.
     *
     * @param array{category?: string, brand?: string|list<string>, price_min?: float, price_max?: float, attr?: array<string, scalar|list<scalar>>, sort?: string, page?: int, per_page?: int, all?: bool, anonymousId?: string, image_width?: int, language?: string, correct?: bool, ignore?: list<string>} $params
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

        // Booleans go as 0/1: the query string has no true/false.
        foreach (['all', 'correct'] as $flag) {
            if (isset($params[$flag])) {
                $params[$flag] = $params[$flag] ? 1 : 0;
            }
        }

        return $this->requester->request('GET', '/search', [
            'auth' => Requester::AUTH_WRITE,
            'query' => $params,
        ]);
    }

    /**
     * suggest('lin'): products by the beginning of words, categories by name and popular queries of the website;
     * redirect — the address of a search rule, corrected — the suggestions are for this corrected query.
     *
     * @param array{limit?: int, image_width?: int, language?: string} $params
     *
     * @return array{products: list<array<string, mixed>>, categories: list<array{id: string, name: string}>, queries: list<string>, redirect?: string|null, corrected?: string|null}
     */
    public function suggest(string $query, array $params = []): array
    {
        /** @var array{products: list<array<string, mixed>>, categories: list<array{id: string, name: string}>, queries: list<string>, redirect?: string|null, corrected?: string|null} $result */
        $result = $this->requester->request('GET', '/search/suggest', [
            'auth' => Requester::AUTH_WRITE,
            'query' => ['q' => $query] + $params,
        ]);

        return $result;
    }

    /**
     * widget('de'): the search widget settings of the website (Site → Search in OneTrace). enabled: false — the widget
     * is off; otherwise the tracker draws the search layer and the results widget, and a store can put
     * <div data-cdp-search-results data-cart-url="…"> on its search page instead of its own results. cart_url — the
     * add-to-cart address from the settings ({id} — the catalog product id), texts — in the visitor's language.
     *
     * @return array{enabled: bool, selector?: string|null, results_url?: string|null, category_url?: string|null, cart_url?: string|null, sections?: array<string, bool>, accent?: string, radius?: int, columns?: int, ratio?: string, voice?: bool, texts?: array<string, string>}
     */
    public function widget(?string $language = null): array
    {
        /** @var array{enabled: bool, selector?: string|null, results_url?: string|null, category_url?: string|null, cart_url?: string|null, sections?: array<string, bool>, accent?: string, radius?: int, columns?: int, ratio?: string, voice?: bool, texts?: array<string, string>} $result */
        $result = $this->requester->request('GET', '/search/widget', [
            'auth' => Requester::AUTH_WRITE,
            'query' => $language !== null ? ['language' => $language] : [],
        ]);

        return $result;
    }
}
