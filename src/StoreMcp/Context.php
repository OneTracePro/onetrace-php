<?php

declare(strict_types=1);

namespace OneTrace\StoreMcp;

/**
 * What the platform passes with a tool call (contract sections 6 and 10.3): the confirmed customer (already checked
 * against the customer token) or a guest (a confirmed email or phone without an account, contract v2), the
 * conversation language, whether the customer agreed to the action, its idempotency key and where the call comes
 * from — the chat bot or an AI shopping assistant (tag orders with it).
 */
final class Context
{
    public const SOURCE_CHAT = 'chat';
    public const SOURCE_ASSISTANT = 'assistant';
    public const ASSISTANTS = ['chatgpt', 'claude', 'perplexity', 'gemini', 'copilot', 'other'];

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

    /**
     * The confirmed email (lower case) or phone (digits with +) of a guest token; null for a customer or no token.
     *
     * @var string|null
     */
    public $guestContact;

    /**
     * "chat" — the OneTrace AI chat bot (also when the platform does not say), "assistant" — an AI shopping assistant.
     *
     * @var string
     */
    public $source;

    /**
     * The AI shopping assistant: chatgpt, claude, perplexity, gemini, copilot or other; null for the chat bot.
     *
     * @var string|null
     */
    public $assistant;

    public function __construct(
        ?string $customerId,
        string $language,
        ?string $conversation,
        bool $confirmed,
        ?string $idempotencyKey,
        ?string $guestContact = null,
        string $source = self::SOURCE_CHAT,
        ?string $assistant = null
    ) {
        $this->customerId = $customerId;
        $this->language = $language;
        $this->conversation = $conversation;
        $this->confirmed = $confirmed;
        $this->idempotencyKey = $idempotencyKey;
        $this->guestContact = $customerId === null ? $guestContact : null;
        $this->source = $source === self::SOURCE_ASSISTANT ? self::SOURCE_ASSISTANT : self::SOURCE_CHAT;
        $this->assistant = $this->source === self::SOURCE_ASSISTANT ? self::assistant($assistant) : null;
    }

    /**
     * The context of a tools/call from its params._meta; the tokens check the customer token.
     *
     * @param array<string, mixed> $meta
     */
    public static function fromMeta(array $meta, CustomerTokens $tokens): self
    {
        $token = \is_string($meta['onetrace/customer_token'] ?? null) ? $meta['onetrace/customer_token'] : null;
        $customerId = $token !== null ? $tokens->verify($token) : null;

        return new self(
            $customerId,
            self::text($meta['onetrace/language'] ?? null) ?? 'en',
            self::text($meta['onetrace/conversation'] ?? null),
            ($meta['onetrace/confirmed'] ?? false) === true,
            self::text($meta['onetrace/idempotency_key'] ?? null),
            $token !== null && $customerId === null ? $tokens->guest($token) : null,
            Values::string($meta['onetrace/source'] ?? null, self::SOURCE_CHAT),
            self::text($meta['onetrace/assistant'] ?? null)
        );
    }

    public function isGuest(): bool
    {
        return $this->customerId === null && $this->guestContact !== null;
    }

    /**
     * The email of the guest, null when the guest confirmed a phone (or it is not a guest).
     */
    public function guestEmail(): ?string
    {
        return $this->guestContact !== null && strpos($this->guestContact, '@') !== false ? $this->guestContact : null;
    }

    /**
     * The phone of the guest, null when the guest confirmed an email (or it is not a guest).
     */
    public function guestPhone(): ?string
    {
        return $this->guestContact !== null && strpos($this->guestContact, '@') === false ? $this->guestContact : null;
    }

    public function fromAssistant(): bool
    {
        return $this->source === self::SOURCE_ASSISTANT;
    }

    private static function assistant(?string $assistant): string
    {
        $assistant = strtolower(trim((string) $assistant));

        return \in_array($assistant, self::ASSISTANTS, true) ? $assistant : 'other';
    }

    /**
     * @param mixed $value
     */
    private static function text($value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}
