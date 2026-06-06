<?php

declare(strict_types=1);

namespace Switon\Jwt\Exception;

/**
 * Exception for JWT header <code>kid</code> mismatches.
 *
 * Raised when caller-expected <code>kid</code> does not equal token header <code>kid</code>.
 *
 * @see \Switon\Jwt\Exception\SignatureException
 * @see \Switon\Jwt\Jwt
 */
class KidMismatchException extends SignatureException
{
}
