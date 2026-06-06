<?php

declare(strict_types=1);

namespace Switon\Jwt\Exception;

use Switon\Jwt\Exception;

/**
 * Exception for malformed JWT tokens.
 *
 * Raised when token structure, header, or payload cannot be parsed as valid JWT data.
 *
 * @see \Switon\Jwt\Exception
 * @see \Switon\Jwt\Jwt
 */
class MalformedException extends Exception
{
}
