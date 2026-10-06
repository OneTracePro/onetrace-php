<?php

declare(strict_types=1);

namespace OneTrace\Http;

use OneTrace\Exception\TransportException;

/**
 * Sends requests over the network. The client ships with a cURL transport and a PSR-18 adapter;
 * implement this interface to use anything else.
 */
interface Transport
{
    /**
     * Returns the response for any HTTP status; throws only when no response was received.
     *
     * @throws TransportException
     */
    public function send(Request $request): Response;
}
