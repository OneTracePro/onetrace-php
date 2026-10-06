<?php

declare(strict_types=1);

namespace OneTrace\Http;

/**
 * An HTTP request prepared by the client and handed to a transport.
 */
final class Request
{
    private string $method;

    private string $url;

    /** @var array<string, string> */
    private array $headers;

    private ?string $body;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(string $method, string $url, array $headers = [], ?string $body = null)
    {
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUrl(): string
    {
        return $this->url;
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
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }
}
