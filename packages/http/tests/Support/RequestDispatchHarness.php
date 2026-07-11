<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Support;

use ReflectionMethod;
use Switon\Http\Event\RequestInvoked;
use Switon\Http\Event\RequestValidating;
use Switon\Http\RequestHandlerInterface;

/**
 * Builds request dispatch events without reflecting inside individual tests.
 */
final class RequestDispatchHarness
{
    public static function validatingEvent(
        RequestHandlerInterface $handler,
        object $controller,
        string $method,
    ): RequestValidating {
        return new RequestValidating($handler, new ReflectionMethod($controller, $method));
    }

    public static function invokedEvent(
        RequestHandlerInterface $handler,
        object $controller,
        string $method,
        mixed $return,
    ): RequestInvoked {
        return new RequestInvoked($handler, new ReflectionMethod($controller, $method), $return);
    }
}
