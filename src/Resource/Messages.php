<?php

declare(strict_types=1);

namespace OneTrace\Resource;

/**
 * Transactional messages: one message to a profile from a template (order confirmation, one-time code) or a cascade
 * of channels tried in order. A profile with the identity is created if there is none. Needs a secret key with
 * messages.send.
 *
 * A transactional template skips consents, frequency limits and quiet hours, but not hard blocks of the channel
 * (the address does not exist, the bot is blocked).
 */
final class Messages extends Resource
{
    /**
     * Queues a message from the template. $options: traits (written to the profile before sending, `traits.*`
     * in the template), data (`context.data.*` in the template), integration_id (when the template's channel has
     * several active integrations). A repeated $idempotencyKey returns the same message.
     *
     * @param array{type: string, value: string} $identity
     * @param array{traits?: array<string, mixed>, data?: array<string, mixed>, integration_id?: int|null} $options
     *
     * @return array<string, mixed> id, status, channel, transactional, origin, profile_id …
     */
    public function send(array $identity, int $templateId, array $options = [], ?string $idempotencyKey = null): array
    {
        return $this->post(self::filled([
            'identity' => $identity,
            'template_id' => $templateId,
            'integration_id' => $options['integration_id'] ?? null,
            'traits' => $options['traits'] ?? null,
            'data' => $options['data'] ?? null,
        ]), $idempotencyKey);
    }

    /**
     * Starts a cascade (plans with the `cascades` feature, otherwise ApiException 402): 2–5 steps, the next one when
     * the profile cannot be reached on the previous channel, or its message was not sent, delivered or read in time.
     * A step: integration_id, template_id, wait_for ("sent" by default, "delivered" or "opened"), timeout for
     * "delivered" / "opened" (["amount" => 2, "unit" => "minute"], 15 minutes by default).
     *
     * @param array{type: string, value: string} $identity
     * @param list<array{integration_id: int, template_id: int, wait_for?: string, timeout?: array{amount: int, unit: string}}> $steps
     * @param array{traits?: array<string, mixed>, data?: array<string, mixed>} $options
     *
     * @return array<string, mixed> id, status (running, reached, failed), step, reached_channel, messages …
     */
    public function sendCascade(array $identity, array $steps, array $options = [], ?string $idempotencyKey = null): array
    {
        return $this->post(self::filled([
            'identity' => $identity,
            'steps' => $steps,
            'traits' => $options['traits'] ?? null,
            'data' => $options['data'] ?? null,
        ]), $idempotencyKey);
    }

    /**
     * A message by id: status (queued, scheduled, sent, delivered, failed, skipped, bounced) and the skip or error reason.
     *
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->requester->request('GET', $this->path('/messages/{id}', ['id' => $id]));
    }

    /**
     * A cascade by id: status, current step, the channel that reached the profile and the messages of its steps.
     *
     * @return array<string, mixed>
     */
    public function cascade(string $id): array
    {
        return $this->requester->request('GET', $this->path('/messages/cascades/{id}', ['id' => $id]));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(array $body, ?string $idempotencyKey): array
    {
        return $this->requester->request('POST', '/messages', [
            'json' => $body,
            'idempotent' => true,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
