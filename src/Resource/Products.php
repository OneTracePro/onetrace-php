<?php

declare(strict_types=1);

namespace OneTrace\Resource;

/**
 * Product catalog for recommendations and emails. Needs a secret key with products.write.
 */
final class Products extends Resource
{
    /**
     * Creates or updates products (by "id") and categories ("id", "name", "parent_id"), up to 1000 of each per call.
     *
     *     upsert([['id' => 'SKU-1', 'name' => 'Sneakers', 'price' => 4990, 'url' => 'https://…', 'image' => 'https://…',
     *              'category_ids' => ['shoes'], 'available' => true]],
     *            [['id' => 'shoes', 'name' => 'Shoes']]);
     *
     * @param array<array<string, mixed>> $items
     * @param array<array<string, mixed>> $categories
     *
     * @return array<string, mixed> saved, categories
     */
    public function upsert(array $items, array $categories = []): array
    {
        $body = ['items' => array_values($items)];

        if ($categories !== []) {
            $body['categories'] = array_values($categories);
        }

        return $this->requester->request('POST', '/products', ['json' => $body]);
    }

    /**
     * Deletes products by id.
     *
     * @param array<string|int> $ids up to 1000
     *
     * @return array<string, mixed> deleted
     */
    public function delete(array $ids): array
    {
        return $this->requester->request('DELETE', '/products', ['json' => ['ids' => array_values(array_map('strval', $ids))]]);
    }
}
