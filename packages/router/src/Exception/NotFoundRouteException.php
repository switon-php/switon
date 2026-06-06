<?php

declare(strict_types=1);

namespace Switon\Routing\Exception;

use Switon\Core\NotFoundInterface;
use Switon\Routing\Exception;

/**
 * Exception for unmatched routes.
 *
 * Raised when no registered route matches the incoming URI and HTTP method.
 *
 * Road-signs:
 * - check verb mismatch
 * - check router prefix (static/?dynamic)
 * - check path normalized (trailing slash, leading /)
 * - check routes registered (RouteRegistrar/RequestMapping)
 * - inspect RouteNotFound event payload
 *
 * @see \Switon\Routing\Exception
 * @see \Switon\Routing\Router
 * @see \Switon\Routing\RouterInterface::match()
 * @see \Switon\Routing\Event\RouteNotFound
 * @see \Switon\Routing\RouteRegistrarInterface
 * @see \Switon\Routing\RouterInterface::getPrefix()
 * @see \Switon\Http\RequestHandlerInterface
 */
class NotFoundRouteException extends Exception implements NotFoundInterface
{
    /**
     * Returns HTTP 404.
     */
    public function getStatusCode(): int
    {
        return 404;
    }
}
