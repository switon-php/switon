<?php

declare(strict_types=1);

namespace Switon\Jwt;

/**
 * Base exception for JWT authentication and validation failures.
 *
 * Returns HTTP status code <code>401</code>.
 *
 * @see \Switon\Jwt\Jwt
 * @see \Switon\Jwt\JwtInterface
 * @see \Switon\Core\Exception
 */
class Exception extends \Switon\Core\Exception
{
    public function getStatusCode(): int
    {
        return 401;
    }
}
