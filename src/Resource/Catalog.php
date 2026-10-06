<?php

declare(strict_types=1);

namespace OneTrace\Resource;

/**
 * Data catalog of the project: events and traits it has seen. Needs a secret key with events.read.
 */
final class Catalog extends Resource
{
    /**
     * Event names with their properties and types.
     *
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        return self::data($this->requester->request('GET', '/catalog/events'));
    }

    /**
     * Profile traits with their types.
     *
     * @return list<array<string, mixed>>
     */
    public function traits(): array
    {
        return self::data($this->requester->request('GET', '/catalog/traits'));
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return list<array<string, mixed>>
     */
    private static function data(array $response): array
    {
        /** @var list<array<string, mixed>> $data */
        $data = isset($response['data']) && \is_array($response['data']) ? array_values($response['data']) : [];

        return $data;
    }
}
