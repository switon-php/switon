<?php

declare(strict_types=1);

namespace Switon\Routing\Exception;

use Switon\Routing\Exception;

/**
 * Exception for unsupported HTTP method on a path that otherwise resolves.
 *
 * Raised by higher HTTP-layer validation when the URI can be routed or mapped but the request method is not allowed.
 *
 * Guidance: Treat this as an HTTP-layer 405 signal; router matching still reports misses with `NotFoundRouteException`.
 *
 * Road-signs:
 * - path resolves, verb does not
 * - RequestHandler probes alternate verbs for 405 fallback
 * - MappingValidatorFilter enforces mapping verb constraints
 * - compare with NotFoundRouteException
 *
 * @see \Switon\Http\RequestHandler::probeMethodNotAllowed()
 * @see \Switon\Http\Filter\MappingValidatorFilter
 * @see \Switon\Routing\Exception\NotFoundRouteException
 */
class MethodNotAllowedException extends Exception
{
    /**
     * Returns HTTP 405.
     */
    public function getStatusCode(): int
    {
        return 405;
    }
}
