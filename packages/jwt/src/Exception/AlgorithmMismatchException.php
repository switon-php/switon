<?php

declare(strict_types=1);

namespace Switon\Jwt\Exception;

use Switon\Jwt\Exception;

/**
 * Exception for JWT algorithm mismatch.
 *
 * Raised when token header <code>alg</code> differs from the configured algorithm.
 *
 * @see \Switon\Jwt\Exception
 * @see \Switon\Jwt\Jwt
 */
class AlgorithmMismatchException extends Exception
{
}
