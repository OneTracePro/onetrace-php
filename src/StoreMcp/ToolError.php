<?php

declare(strict_types=1);

namespace OneTrace\StoreMcp;

/**
 * An error answer of a store MCP tool (contract section 7): code for the platform and a short message for the
 * customer in the conversation language.
 */
final class ToolError extends \RuntimeException
{
    public const AUTH_REQUIRED = 'auth_required';
    public const INVALID_CODE = 'invalid_code';
    public const NOT_FOUND = 'not_found';
    public const NOT_ALLOWED = 'not_allowed';
    public const INVALID_INPUT = 'invalid_input';
    public const RATE_LIMITED = 'rate_limited';
    public const UNAVAILABLE = 'unavailable';

    /** @var string */
    private $errorCode;

    public function __construct(string $errorCode, string $message = '')
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
