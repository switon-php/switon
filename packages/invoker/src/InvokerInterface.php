<?php

declare(strict_types=1);

namespace Switon\Invoking;

/**
 * Invokes callables with argument values from explicit input, defaults, and DI.
 *
 * Guidance:
 * - use this when you want the DI invoker plus invocation guards and interceptors
 * - pass positional, named, or type-keyed overrides in <code>$parameters</code>
 *
 * @see \Switon\Invoking\Invoker
 * @see \Switon\Invocation\Attribute\GuardInterface
 * @see \Switon\Invocation\Attribute\InterceptorInterface
 *
 * Road-signs:
 * - Resolution: positional → named → type-keyed overrides → container ({@see \Switon\Di\Injector::resolveDependency()})
 * - Guards: `GuardInterface` may short-circuit; interceptors wrap the call
 * - Boundary: framework code that needs interception uses this, not raw `call_user_func`
 */
interface InvokerInterface
{
    /**
     * Invokes a callable, including invokable objects, with resolved parameters.
     *
     * @param callable $callable Callable target to execute.
     * @param array<int|string, mixed> $parameters Positional, named, type-keyed, or variadic overrides.
     *
     * @return mixed Callable return value
     */
    public function invoke(callable $callable, array $parameters = []): mixed;
}
