<?php

declare(strict_types=1);

namespace OneTrace\Exception;

/**
 * HTTP 409: the state does not allow the action, or a request with the same idempotency key is still running.
 */
class ConflictException extends ApiException
{
}
