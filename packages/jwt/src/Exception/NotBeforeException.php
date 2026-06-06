<?php

declare(strict_types=1);

namespace Switon\Jwt\Exception;

use Switon\Jwt\Exception;

/**
 * Exception for JWT not-before validation failures.
 *
 * Raised when current time is before the token <code>nbf</code> claim.
 *
 * @see \Switon\Jwt\Exception
 * @see \Switon\Jwt\Jwt
 */
class NotBeforeException extends Exception
{
}
