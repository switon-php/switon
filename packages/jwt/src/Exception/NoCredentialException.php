<?php

declare(strict_types=1);

namespace Switon\Jwt\Exception;

use Switon\Jwt\Exception;

/**
 * Exception for missing JWT credentials.
 *
 * Raised when token input is empty or unavailable for JWT operations.
 *
 * @see \Switon\Jwt\Exception
 * @see \Switon\Jwt\Jwt
 */
class NoCredentialException extends Exception
{
}
