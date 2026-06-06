<?php

declare(strict_types=1);

namespace Switon\Invocation\Attribute;

use ReflectionMethod;
use Throwable;

/**
 * Defines interception hooks around reflected method invocation.
 *
 * Guidance:
 * - implement this on method attributes that need before/after/exception hooks
 * - hooks run only when the shared invoker enables the invocation pipeline
 *
 * @see \Switon\Invoking\Invoker
 * @see \Switon\Invoking\InvokerInterface
 */
interface InterceptorInterface
{
    /** Runs before the target method; throw to stop normal invocation. */
    public function preHandle(ReflectionMethod $method): bool;

    /** Runs after successful execution; implementations may rewrite the return value by reference. */
    public function postHandle(ReflectionMethod $method, mixed &$return): void;

    /** Called when target execution throws so the interceptor can observe or rethrow. */
    public function onException(ReflectionMethod $method, Throwable $exception): void;
}
