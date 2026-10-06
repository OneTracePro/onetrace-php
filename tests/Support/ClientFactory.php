<?php

declare(strict_types=1);

namespace OneTrace\Tests\Support;

use OneTrace\Client;

trait ClientFactory
{
    /** @var list<float> */
    protected array $sleeps = [];

    /**
     * @param array<string, mixed> $options
     */
    protected function client(FakeTransport $transport, array $options = []): Client
    {
        /** @var array{write_key?: string|null, secret_key?: string|null, max_retries?: int, retry_delay?: float} $options */
        $options += ['write_key' => 'cdp_wk_test', 'secret_key' => 'cdp_sk_test'];

        return new Client('https://cdp.example.com', $options + [
            'transport' => $transport,
            'sleep' => function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        ]);
    }
}
