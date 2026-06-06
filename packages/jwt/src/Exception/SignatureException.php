<?php

declare(strict_types=1);

namespace Switon\Jwt\Exception;

use Switon\Jwt\Exception;

/**
 * Exception for JWT signature verification failures.
 *
 * Raised when token signature does not match the expected signing key and algorithm.
 *
 * @see \Switon\Jwt\Exception
 * @see \Switon\Jwt\Jwt
 */
class SignatureException extends Exception
{
}
