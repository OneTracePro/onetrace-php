<?php

declare(strict_types=1);

namespace OneTrace\Http;

use OneTrace\Exception\TransportException;

/**
 * Default transport on ext-curl. One handle is reused between requests, so connections are kept alive
 * while a script or a worker sends many requests.
 */
final class CurlTransport implements Transport
{
    private float $timeout;

    private float $connectTimeout;

    /**
     * A cURL resource on PHP 7.4, a CurlHandle object on PHP 8 (described for the lowest supported version).
     *
     * @var resource|null
     */
    private $handle;

    /**
     * @param float $timeout        seconds for the whole request
     * @param float $connectTimeout seconds to establish the connection
     */
    public function __construct(float $timeout = 10.0, float $connectTimeout = 5.0)
    {
        if (!\extension_loaded('curl')) {
            throw new \LogicException('The cURL extension is required for CurlTransport; pass a PSR-18 client via the "http_client" option instead.');
        }

        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    public function __destruct()
    {
        // Since PHP 8.0 the handle is an object freed with the transport; curl_close() is a no-op there
        // and deprecated in 8.5.
        if ($this->handle !== null && \PHP_VERSION_ID < 80000) {
            curl_close($this->handle);
        }

        $this->handle = null;
    }

    public function send(Request $request): Response
    {
        if ($this->handle === null) {
            $handle = curl_init();

            if ($handle === false) {
                throw new TransportException('Failed to initialize cURL.');
            }

            $this->handle = $handle;
        } else {
            curl_reset($this->handle);
        }

        $url = $request->getUrl();
        $method = $request->getMethod();

        if ($url === '' || $method === '') {
            throw new \InvalidArgumentException('A request needs a method and a URL.');
        }

        $headers = [];
        $lines = [];

        foreach ($request->getHeaders() as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        // An empty Expect header stops cURL from waiting for "100 Continue" on large bodies.
        $lines[] = 'Expect:';

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);

                if (\count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                } elseif (strpos($line, 'HTTP/') === 0) {
                    // A new status line (after a redirect or "100 Continue") starts a new header block.
                    $headers = [];
                }

                return \strlen($line);
            },
        ];

        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }

        if ($request->getBody() !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->getBody();
        }

        curl_setopt_array($this->handle, $options);
        $body = curl_exec($this->handle);

        if ($body === false || !\is_string($body)) {
            $message = curl_error($this->handle);
            $code = curl_errno($this->handle);

            throw new TransportException(sprintf('%s %s failed: %s', $request->getMethod(), $request->getUrl(), $message !== '' ? $message : 'cURL error ' . $code), $code);
        }

        return new Response((int) curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE), $headers, $body);
    }
}
