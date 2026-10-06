<?php

declare(strict_types=1);

namespace OneTrace\Exception;

/**
 * HTTP 429: the rate limit or the monthly event quota is exceeded. Retried automatically (respecting
 * Retry-After) before it is thrown.
 */
class RateLimitException extends ApiException
{
    /**
     * Seconds to wait before the next request, if the server said so.
     */
    public function getRetryAfter(): ?int
    {
        $value = $this->getResponse()->getHeader('Retry-After');

        if ($value === null || $value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $time = strtotime($value);

        return $time === false ? null : max(0, $time - time());
    }
}
