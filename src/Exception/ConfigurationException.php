<?php

declare(strict_types=1);

namespace OneTrace\Exception;

/**
 * The client is misconfigured, for example a management method is called without a secret key.
 */
class ConfigurationException extends \LogicException implements OneTraceException
{
}
