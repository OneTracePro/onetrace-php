<?php

declare(strict_types=1);

namespace OneTrace\StoreMcp;

/**
 * What the platform passes with a tool call (contract section 6): the confirmed customer (already checked against
 * the customer token), the conversation language, whether the customer agreed to the action and its idempotency key.
 */
final class Context
{
    /** @var string|null */
    public $customerId;

    /** @var string */
    public $language;

    /** @var string|null */
    public $conversation;

    /** @var bool */
    public $confirmed;

    /** @var string|null */
    public $idempotencyKey;

    public function __construct(?string $customerId, string $language, ?string $conversation, bool $confirmed, ?string $idempotencyKey)
    {
        $this->customerId = $customerId;
        $this->language = $language;
        $this->conversation = $conversation;
        $this->confirmed = $confirmed;
        $this->idempotencyKey = $idempotencyKey;
    }
}
