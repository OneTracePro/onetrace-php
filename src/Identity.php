<?php

declare(strict_types=1);

namespace OneTrace;

/**
 * Profile identifiers for methods that take an identity: Identity::email('anna@example.com').
 * Values are normalized by the server the same way as at ingestion (lower-case email, E.164 phone).
 */
final class Identity
{
    public const USER_ID = 'user_id';
    public const ANONYMOUS_ID = 'anonymous_id';
    public const EMAIL = 'email';
    public const PHONE = 'phone';
    public const TELEGRAM_CHAT_ID = 'telegram_chat_id';
    public const VIBER_ID = 'viber_id';
    public const WEB_PUSH = 'web_push';

    public const TYPES = [self::USER_ID, self::ANONYMOUS_ID, self::EMAIL, self::PHONE, self::TELEGRAM_CHAT_ID, self::VIBER_ID, self::WEB_PUSH];

    private function __construct()
    {
    }

    /**
     * @param string|int $value
     *
     * @return array{type: string, value: string}
     */
    public static function of(string $type, $value): array
    {
        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown identity type "%s"; expected one of: %s.', $type, implode(', ', self::TYPES)));
        }

        $value = (string) $value;

        if ($value === '') {
            throw new \InvalidArgumentException(sprintf('The %s identity value must not be empty.', $type));
        }

        return ['type' => $type, 'value' => $value];
    }

    /**
     * @param string|int $id
     *
     * @return array{type: string, value: string}
     */
    public static function userId($id): array
    {
        return self::of(self::USER_ID, $id);
    }

    /**
     * @return array{type: string, value: string}
     */
    public static function anonymousId(string $id): array
    {
        return self::of(self::ANONYMOUS_ID, $id);
    }

    /**
     * @return array{type: string, value: string}
     */
    public static function email(string $email): array
    {
        return self::of(self::EMAIL, $email);
    }

    /**
     * @return array{type: string, value: string}
     */
    public static function phone(string $phone): array
    {
        return self::of(self::PHONE, $phone);
    }

    /**
     * @param string|int $chatId
     *
     * @return array{type: string, value: string}
     */
    public static function telegramChatId($chatId): array
    {
        return self::of(self::TELEGRAM_CHAT_ID, $chatId);
    }

    /**
     * The id of the user at the project's Viber bot (set when the user subscribes through viberLink()).
     *
     * @return array{type: string, value: string}
     */
    public static function viberId(string $viberId): array
    {
        return self::of(self::VIBER_ID, $viberId);
    }

    /**
     * @return array{type: string, value: string}
     */
    public static function webPush(string $subscriptionId): array
    {
        return self::of(self::WEB_PUSH, $subscriptionId);
    }
}
