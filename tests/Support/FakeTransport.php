<?php

declare(strict_types=1);

namespace OneTrace\Tests\Support;

use OneTrace\Http\Request;
use OneTrace\Http\Response;
use OneTrace\Http\Transport;

/**
 * Returns queued responses (or throws queued exceptions) and records the requests.
 */
final class FakeTransport implements Transport
{
    /** @var list<Response|\Throwable> */
    private array $queue = [];

    /** @var list<Request> */
    public array $requests = [];

    /**
     * @param array<string, mixed>|list<mixed>|null $json
     * @param array<string, string>                 $headers
     */
    public function push(int $status = 200, $json = [], array $headers = []): self
    {
        $this->queue[] = new Response($status, $headers + ['Content-Type' => 'application/json'], $json === null ? '' : (string) json_encode($json));

        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function raw(int $status, string $body, array $headers = []): self
    {
        $this->queue[] = new Response($status, $headers, $body);

        return $this;
    }

    public function fail(\Throwable $error): self
    {
        $this->queue[] = $error;

        return $this;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? new Response(200, [], '{}');

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function last(): Request
    {
        $request = end($this->requests);

        if ($request === false) {
            throw new \LogicException('No requests were sent.');
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    public function lastJson(): array
    {
        $decoded = json_decode((string) $this->last()->getBody(), true);

        return \is_array($decoded) ? $decoded : [];
    }
}
