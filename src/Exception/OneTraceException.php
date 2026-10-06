<?php

declare(strict_types=1);

namespace OneTrace\Exception;

/**
 * Marker for every exception thrown by the library: catch it to handle all of them at once.
 */
interface OneTraceException extends \Throwable
{
}
