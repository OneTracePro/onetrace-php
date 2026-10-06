<?php

declare(strict_types=1);

namespace OneTrace\Exception;

/**
 * HTTP 413: the request body is over 1 MB.
 */
class PayloadTooLargeException extends ApiException
{
}
