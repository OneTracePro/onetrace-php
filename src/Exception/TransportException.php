<?php

declare(strict_types=1);

namespace OneTrace\Exception;

/**
 * No response was received: DNS, connection, TLS or timeout error. Retried automatically before it is thrown.
 */
class TransportException extends \RuntimeException implements OneTraceException
{
}
