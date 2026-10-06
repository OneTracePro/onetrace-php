<?php

declare(strict_types=1);

namespace OneTrace\Exception;

/**
 * HTTP 5xx: a temporary server error; retried automatically before it is thrown.
 */
class ServerException extends ApiException
{
}
