<?php

declare(strict_types=1);

namespace OneTrace\Resource;

use OneTrace\Http\Requester;
use OneTrace\Identity;
use OneTrace\Page;

/**
 * Base of the API resources: path building and cursor iteration.
 *
 * @internal
 */
abstract class Resource
{
    protected Requester $requester;

    final public function __construct(Requester $requester)
    {
        $this->requester = $requester;
    }

    /**
     * Fills {name} placeholders with URL-encoded values: path('/segments/{id}', ['id' => 5]).
     *
     * @param array<string, string|int> $params
     */
    protected function path(string $template, array $params = []): string
    {
        $replace = [];

        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = rawurlencode((string) $value);
        }

        return strtr($template, $replace);
    }

    /**
     * @return array{type: string, value: string}
     */
    protected function identity(string $type, string $value): array
    {
        return Identity::of($type, $value);
    }

    /**
     * Walks all pages of a cursor list and yields items one by one.
     *
     * @param callable(?string): Page $fetch receives the cursor of the page to load
     *
     * @return \Generator<int, array<string, mixed>>
     */
    protected function paginate(callable $fetch, ?string $cursor = null): \Generator
    {
        do {
            $page = $fetch($cursor);

            foreach ($page as $item) {
                yield $item;
            }

            $cursor = $page->getNextCursor();
        } while ($cursor !== null && $page->count() > 0);
    }

    /**
     * Drops null values: optional parameters are not sent at all.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    protected static function filled(array $values): array
    {
        return array_filter($values, static function ($value): bool {
            return $value !== null;
        });
    }
}
