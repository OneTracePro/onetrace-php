<?php

declare(strict_types=1);

namespace OneTrace\Exception;

/**
 * HTTP 403: the key lacks the permission, the site domain is not allowed or the account is suspended.
 */
class PermissionException extends ApiException
{
}
