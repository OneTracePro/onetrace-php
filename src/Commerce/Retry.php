<?php

declare(strict_types=1);

namespace OneTrace\Commerce;

use OneTrace\Exception\AuthenticationException;
use OneTrace\Exception\PayloadTooLargeException;
use OneTrace\Exception\PaymentRequiredException;
use OneTrace\Exception\PermissionException;
use OneTrace\Exception\RateLimitException;
use OneTrace\Exception\ServerException;
use OneTrace\Exception\TransportException;
use OneTrace\Exception\ValidationException;

/**
 * Retry policy of plugin queues: what to do with a batch that failed, and when to try again.
 */
final class Retry
{
    /** Pauses between attempts, seconds: 1, 5 and 30 minutes, 2 and 12 hours. */
    public const DELAYS = [60, 300, 1800, 7200, 43200];

    /** Send again later: the platform is unavailable, busy or overloaded. */
    public const RETRY = 'retry';

    /** Drop and log: the data is wrong (422), repeating does not help. */
    public const DROP = 'drop';

    /** Stop sending until the settings are fixed: the key is invalid or lacks permissions. */
    public const SETTINGS = 'settings';

    public static function decide(\Throwable $error): string
    {
        if ($error instanceof AuthenticationException || $error instanceof PermissionException) {
            return self::SETTINGS;
        }

        if ($error instanceof ValidationException || $error instanceof PayloadTooLargeException) {
            return self::DROP;
        }

        // 402: the monthly event limit of the plan is reached — it frees up with the next month or a plan change.
        if ($error instanceof RateLimitException || $error instanceof ServerException || $error instanceof TransportException || $error instanceof PaymentRequiredException) {
            return self::RETRY;
        }

        return self::DROP;
    }

    /**
     * Pause before the attempt number $attempt (1 — the first retry), null when the attempts are over.
     */
    public static function delay(int $attempt): ?int
    {
        return self::DELAYS[$attempt - 1] ?? null;
    }
}
