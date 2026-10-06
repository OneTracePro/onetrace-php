<?php

declare(strict_types=1);

namespace OneTrace;

use OneTrace\Resource\Events;

/**
 * Collects events and sends them in batches: fewer requests when a script or a worker sends many events.
 *
 *     $buffer = $client->events()->buffer();
 *     $buffer->track(['userId' => '42', 'event' => 'product_viewed', 'properties' => ['product_id' => 'SKU-1']]);
 *     $buffer->flush(); // or let the destructor send the rest at the end of the script
 *
 * Messages are validated and timestamped when queued, so they keep the time of the action.
 */
final class EventBuffer implements \Countable
{
    private Events $events;

    private int $flushAt;

    /** @var callable(\Throwable, list<array<string, mixed>>): void|null */
    private $onError;

    /** @var list<array<string, mixed>> */
    private array $queue = [];

    /**
     * @param callable(\Throwable, list<array<string, mixed>>): void|null $onError
     */
    public function __construct(Events $events, int $flushAt = 100, ?callable $onError = null)
    {
        $this->events = $events;
        $this->flushAt = max(1, min($flushAt, Events::MAX_BATCH));
        $this->onError = $onError;
    }

    public function __destruct()
    {
        if ($this->queue === []) {
            return;
        }

        try {
            $this->flush();
        } catch (\Throwable $e) {
            $this->report($e, $this->queue);
            $this->queue = [];
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    public function track(array $message): void
    {
        $this->push('track', $message);
    }

    /**
     * @param array<string, mixed> $message
     */
    public function identify(array $message): void
    {
        $this->push('identify', $message);
    }

    /**
     * @param array<string, mixed> $message
     */
    public function page(array $message): void
    {
        $this->push('page', $message);
    }

    /**
     * @param array<string, mixed> $message
     */
    public function alias(array $message): void
    {
        $this->push('alias', $message);
    }

    /**
     * Sends everything queued. On failure the messages stay queued and the exception is thrown; flushing again
     * repeats them with the same messageIds, so nothing is counted twice.
     *
     * @return array{accepted: int, duplicates: int}
     */
    public function flush(): array
    {
        if ($this->queue === []) {
            return ['accepted' => 0, 'duplicates' => 0];
        }

        $result = $this->events->sendPrepared($this->queue);
        $this->queue = [];

        return $result;
    }

    /**
     * Messages waiting to be sent.
     */
    public function count(): int
    {
        return \count($this->queue);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function push(string $type, array $message): void
    {
        $this->queue[] = Events::prepare($type, $message);

        if (\count($this->queue) >= $this->flushAt) {
            $this->flush();
        }
    }

    /**
     * @param list<array<string, mixed>> $lost
     */
    private function report(\Throwable $error, array $lost): void
    {
        if ($this->onError !== null) {
            ($this->onError)($error, $lost);

            return;
        }

        trigger_error(sprintf('OneTrace: %d events were not sent: %s', \count($lost), $error->getMessage()), E_USER_WARNING);
    }
}
