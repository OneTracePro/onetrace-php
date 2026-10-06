<?php

declare(strict_types=1);

namespace OneTrace\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use OneTrace\Client;
use OneTrace\Exception\TransportException;
use OneTrace\Http\CurlTransport;
use OneTrace\Http\Psr18Transport;
use OneTrace\Http\Request;
use OneTrace\Tests\Support\EchoPsr18Client;
use PHPUnit\Framework\TestCase;

/**
 * The cURL transport against PHP's built-in web server, and the PSR-18 adapter.
 */
final class TransportTest extends TestCase
{
    /** @var resource|null */
    private static $server;

    private static string $address = '';

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        self::$address = 'http://' . $name;

        $command = [PHP_BINARY, '-S', $name, __DIR__ . '/Support/server.php'];
        $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($process);
        self::$server = $process;

        for ($i = 0; $i < 50; $i++) {
            $connection = @fsockopen('127.0.0.1', (int) substr($name, strrpos($name, ':') + 1), $errno, $error, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(100000);
        }

        self::fail('The test web server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    public function testSendsMethodHeadersAndBody(): void
    {
        $transport = new CurlTransport();

        $response = $transport->send(new Request('POST', self::$address . '/echo?x=1', ['Authorization' => 'Bearer k', 'Content-Type' => 'application/json'], '{"a":"ё"}'));

        self::assertSame(200, $response->getStatus());
        self::assertSame('yes', $response->getHeader('X-Test-Server'));
        $echo = json_decode($response->getBody(), true);
        self::assertSame('POST', $echo['method']);
        self::assertSame('/echo?x=1', $echo['uri']);
        self::assertSame('Bearer k', $echo['authorization']);
        self::assertSame('{"a":"ё"}', $echo['body']);
    }

    public function testReturnsErrorStatusesAsResponses(): void
    {
        $response = (new CurlTransport())->send(new Request('GET', self::$address . '/status/503'));

        self::assertSame(503, $response->getStatus());
        self::assertSame('{"message":"status 503"}', $response->getBody());
    }

    public function testReusesTheHandleBetweenRequests(): void
    {
        $transport = new CurlTransport();

        self::assertSame(200, $transport->send(new Request('DELETE', self::$address . '/echo', [], '{"ids":["1"]}'))->getStatus());
        self::assertSame('GET', json_decode($transport->send(new Request('GET', self::$address . '/echo'))->getBody(), true)['method']);
    }

    public function testTimesOut(): void
    {
        $this->expectException(TransportException::class);

        (new CurlTransport(0.3, 0.3))->send(new Request('GET', self::$address . '/slow'));
    }

    public function testConnectionErrorsAreTransportExceptions(): void
    {
        $this->expectException(TransportException::class);

        (new CurlTransport(1.0, 1.0))->send(new Request('GET', 'http://127.0.0.1:1/'));
    }

    public function testTheClientWorksOverCurl(): void
    {
        $client = new Client(self::$address, ['write_key' => 'cdp_wk_x', 'max_retries' => 0]);

        $echo = $client->events()->track(['userId' => '42', 'event' => 'x']);

        self::assertSame('/api/v1/track', $echo['uri']);
        self::assertSame('Bearer cdp_wk_x', $echo['authorization']);
    }

    public function testPsr18Adapter(): void
    {
        $factory = new Psr17Factory();
        $client = new EchoPsr18Client();
        $transport = new Psr18Transport($client, $factory, $factory);

        $response = $transport->send(new Request('PATCH', 'https://cdp.example.com/api/v1/segments/5', ['Authorization' => 'Bearer k'], '{"name":"VIP"}'));

        self::assertSame(201, $response->getStatus());
        self::assertSame('a, b', $response->getHeader('x-echo'));
        self::assertNotNull($client->last);
        self::assertSame('Bearer k', $client->last->getHeaderLine('Authorization'));
        self::assertSame(['method' => 'PATCH', 'uri' => 'https://cdp.example.com/api/v1/segments/5', 'body' => '{"name":"VIP"}'], json_decode($response->getBody(), true));
    }

    public function testTheClientAcceptsAPsr18Client(): void
    {
        $factory = new Psr17Factory();
        $client = new Client('https://cdp.example.com', ['secret_key' => 'cdp_sk_x', 'http_client' => new EchoPsr18Client(), 'request_factory' => $factory, 'stream_factory' => $factory]);

        self::assertSame('GET', $client->segments()->get(5)['method']);
    }
}
