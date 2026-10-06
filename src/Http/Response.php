<?php

declare(strict_types=1);

namespace OneTrace\Http;

/**
 * An HTTP response returned by a transport. Header names are stored in lower case.
 */
final class Response
{
    private int $status;

    /** @var array<string, string> */
    private array $headers = [];

    private string $body;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $status, array $headers = [], string $body = '')
    {
        $this->status = $status;
        $this->body = $body;

        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
