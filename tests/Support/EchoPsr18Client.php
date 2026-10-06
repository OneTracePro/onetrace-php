<?php

declare(strict_types=1);

namespace OneTrace\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers with the request it received.
 */
final class EchoPsr18Client implements ClientInterface
{
    public ?RequestInterface $last = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->last = $request;

        return new Response(201, ['Content-Type' => 'application/json', 'X-Echo' => ['a', 'b']], (string) json_encode([
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'body' => (string) $request->getBody(),
        ]));
    }
}
