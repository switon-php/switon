<?php

declare(strict_types=1);

namespace Switon\Jwt\Exception;

use Switon\Jwt\Exception;

/**
 * Exception for expired JWT tokens.
 *
 * Raised when current time is after the token <code>exp</code> claim.
 *
 * @see \Switon\Jwt\Exception
 * @see \Switon\Jwt\Jwt
 */
class ExpiredException extends Exception
{
}
