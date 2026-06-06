<?php

declare(strict_types=1);

namespace Switon\Principal\Exception;

use Switon\Principal\Exception as BaseException;

/**
 * Use when identity-dependent code reads authenticated user data while the current runtime is still guest.
 *
 * Cause: maps to HTTP 401 via getStatusCode().
 *
 * @see \Switon\Http\Exception\UnauthorizedException
 * @see \Switon\Principal\IdentityInterface
 * @see \Switon\Http\UnauthorizedHandler
 */
class NotAuthenticatedException extends BaseException
{
    /**
     * {@inheritDoc}
     */
    public function getStatusCode(): int
    {
        return 401;
    }
}
