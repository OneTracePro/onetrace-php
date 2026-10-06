<?php

declare(strict_types=1);

namespace OneTrace\Http;

use OneTrace\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends requests through any PSR-18 client (Guzzle 7, Symfony HttpClient, …). Timeouts and proxies are
 * configured on that client.
 */
final class Psr18Transport implements Transport
{
    private ClientInterface $client;

    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    public function __construct(ClientInterface $client, RequestFactoryInterface $requestFactory, StreamFactoryInterface $streamFactory)
    {
        $this->client = $client;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    public function send(Request $request): Response
    {
        $psrRequest = $this->requestFactory->createRequest($request->getMethod(), $request->getUrl());

        foreach ($request->getHeaders() as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }

        if ($request->getBody() !== null) {
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($request->getBody()));
        }

        try {
            $psrResponse = $this->client->sendRequest($psrRequest);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException(sprintf('%s %s failed: %s', $request->getMethod(), $request->getUrl(), $e->getMessage()), (int) $e->getCode(), $e);
        }

        $headers = [];

        foreach ($psrResponse->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        return new Response($psrResponse->getStatusCode(), $headers, (string) $psrResponse->getBody());
    }
}
