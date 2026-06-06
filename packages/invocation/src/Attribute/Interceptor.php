<?php

declare(strict_types=1);

namespace Switon\Invocation\Attribute;

use ReflectionMethod;
use Throwable;

/**
 * Default no-op interceptor base class.
 *
 * Guidance: Extend when you want interceptor lifecycle hooks without implementing every method yourself.
 *
 * @see \Switon\Invocation\Attribute\InterceptorInterface
 * @see \Switon\Invoking\InvokerInterface
 */
class Interceptor implements InterceptorInterface
{
    /** {@inheritDoc} */
    public function preHandle(ReflectionMethod $method): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public function postHandle(ReflectionMethod $method, mixed &$return): void
    {
    }

    /** {@inheritDoc} */
    public function onException(ReflectionMethod $method, Throwable $exception): void
    {
    }
}
