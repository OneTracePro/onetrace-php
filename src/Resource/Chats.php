<?php

declare(strict_types=1);

namespace OneTrace\Resource;

use OneTrace\Page;

/**
 * Online chat conversations (website and messengers) for bots and external helpdesk systems: read conversations,
 * answer customers and close conversations. Needs a secret key with chats.read (reading) or chats.reply (answers).
 *
 * An answer through the API takes no operator seat: the operator inbox signs it with the name of the key, and the
 * customer gets it in the website widget or in the messenger of the conversation.
 */
final class Chats extends Resource
{
    /**
     * Conversations, newest first.
     *
     * @param array<string, mixed> $params status ("open" — waiting for an answer, "waiting", "closed"), channel
     *                                     ("web", "telegram", "whatsapp", "viber"), profile (Identity::email(…)),
     *                                     limit (1–200, 50 by default), cursor
     */
    public function conversations(array $params = []): Page
    {
        return Page::fromResponse($this->requester->request('GET', '/conversations', ['query' => self::query($params)]));
    }

    /**
     * All conversations matching the filters across pages.
     *
     * @param array<string, mixed> $params status, channel, profile, limit
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateConversations(array $params = []): \Generator
    {
        return $this->paginate(function (?string $cursor) use ($params): Page {
            return $this->conversations(self::filled($params + ['cursor' => $cursor]));
        });
    }

    /**
     * A conversation with its latest messages, oldest first (100 by default, up to 500). A message has author
     * (visitor, operator, system with action), via (inbox or api), api_key and the delivery status of a messenger answer.
     *
     * @return array<string, mixed>
     */
    public function conversation(string $id, ?int $messages = null): array
    {
        return $this->requester->request('GET', $this->path('/conversations/{id}', ['id' => $id]), [
            'query' => self::filled(['limit' => $messages]),
        ]);
    }

    /**
     * Answers the customer as the API key. An answer to a closed conversation reopens it; WhatsApp allows free text
     * only within 24 hours of the customer's last message (ValidationException otherwise). A repeated
     * $idempotencyKey does not answer twice.
     *
     * @return array<string, mixed> the message
     */
    public function reply(string $id, string $body, ?string $idempotencyKey = null): array
    {
        return $this->requester->request('POST', $this->path('/conversations/{id}/messages', ['id' => $id]), [
            'json' => ['body' => $body],
            'idempotent' => true,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * Closes the conversation; the website visitor is asked to rate it.
     *
     * @return array<string, mixed> the conversation
     */
    public function close(string $id): array
    {
        return $this->requester->request('POST', $this->path('/conversations/{id}/close', ['id' => $id]));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function query(array $params): array
    {
        if (isset($params['profile']) && \is_array($params['profile'])) {
            $params['profile_type'] = $params['profile']['type'] ?? null;
            $params['profile_value'] = $params['profile']['value'] ?? null;
        }

        unset($params['profile']);

        return self::filled($params);
    }
}
