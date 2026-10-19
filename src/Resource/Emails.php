<?php

declare(strict_types=1);

namespace OneTrace\Resource;

/**
 * Store emails through OneTrace: a ready email of the shop (order, registration, password reset, invoice) goes out
 * through the project's email integration as is, with statuses and history in the customer profile. Order emails
 * with order data can be built from OneTrace templates instead (the store emails section). Needs a secret key with
 * messages.send.
 *
 * Send the email yourself when the call fails: an ApiException (no email integration, the message quota is used up,
 * too many emails to the recipient) or a TransportException (the platform is unavailable).
 */
final class Emails extends Resource
{
    /** Order emails that a OneTrace template can build from `order` data. */
    public const ORDER_KINDS = ['order_placed', 'order_paid', 'order_shipped', 'order_delivered', 'order_cancelled', 'order_refunded'];

    /** Emails with access links: sent without click tracking, only the subject is kept after sending. */
    public const ACCESS_KINDS = ['password_reset', 'account_new', 'email_verification', 'login_link', 'login_code'];

    /** What to do with an email of the shop: build it from the OneTrace template, relay it as is, or send it natively. */
    public const TEMPLATE = 'template';

    public const RELAY = 'relay';

    public const NATIVE = 'native';

    /**
     * Sends a store email. $email: to, kind (order_placed, password_reset, other …, [a-z0-9_]), subject and html
     * (required unless an order template is enabled for the kind and order is given), text, reply_to, from_name,
     * language (the customer's language; picks the template version), integration_id, traits (written to the profile
     * first), attachments (see attachment()), order (see Commerce\Order::emailData()). A repeated $idempotencyKey
     * returns the same message.
     *
     * @param array<string, mixed> $email
     *
     * @return array<string, mixed> id, status, kind, template (built from a OneTrace template), profile_id …
     */
    public function send(array $email, ?string $idempotencyKey = null): array
    {
        return $this->requester->request('POST', '/emails', [
            'json' => self::filled($email),
            'idempotent' => true,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * Settings of the store emails section: relay (send all store emails through OneTrace), integration_id and
     * templates — order email kinds built from OneTrace templates (kind => bool). Cache it for a few minutes.
     *
     * @return array{relay: bool, integration_id: int|null, templates: array<string, bool>}
     */
    public function settings(): array
    {
        $settings = $this->requester->request('GET', '/emails/settings');

        return [
            'relay' => ($settings['relay'] ?? false) === true,
            'integration_id' => is_numeric($settings['integration_id'] ?? null) ? (int) $settings['integration_id'] : null,
            'templates' => array_map('boolval', (array) ($settings['templates'] ?? [])),
        ];
    }

    /**
     * How to deliver an email of $kind with these settings: TEMPLATE — do not render the shop email, send the order
     * data; RELAY — send the shop email as is; NATIVE — the shop sends it itself.
     *
     * @param array{relay?: bool, templates?: array<string, bool>} $settings
     */
    public static function route(array $settings, string $kind): string
    {
        if (in_array($kind, self::ORDER_KINDS, true) && ($settings['templates'][$kind] ?? false) === true) {
            return self::TEMPLATE;
        }

        return ($settings['relay'] ?? false) === true ? self::RELAY : self::NATIVE;
    }

    /**
     * An attachment for send(): up to 5 files of 5 MB in total — PDF, PNG, JPEG, GIF, plain text, CSV or calendar.
     *
     * @return array{name: string, type: string, content: string}
     */
    public static function attachment(string $name, string $type, string $contents): array
    {
        return ['name' => $name, 'type' => $type, 'content' => base64_encode($contents)];
    }
}
