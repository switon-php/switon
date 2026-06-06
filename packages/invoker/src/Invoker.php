<?php

declare(strict_types=1);

namespace Switon\Invoking;

use ReflectionAttribute;
use ReflectionFunction;
use ReflectionMethod;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ContainerInterface;
use Switon\Di\InvokerInterface as BaseInvokerInterface;
use Switon\Invocation\Attribute\GuardInterface;
use Switon\Invocation\Attribute\InterceptorInterface;
use Closure;
use Throwable;

/**
 * Invokes callables with argument values from explicit input, defaults, and DI.
 *
 * Guidance:
 * - use this when guard and interceptor attributes must participate in invocation
 * - plain functions, closures, and constructors fall back to the base DI invoker path
 * - class methods and invokable <code>__invoke()</code> methods can participate in guard/interceptor hooks
 *
 * @see \Switon\Invocation\Attribute\GuardInterface
 * @see \Switon\Invocation\Attribute\InterceptorInterface
 */
class Invoker implements InvokerInterface
{
    #[Autowired] protected ContainerInterface $container;

    #[Autowired] protected BaseInvokerInterface $baseInvoker;

    /**
     * Invokes the callable, running method guards and interceptors when the target is a reflected method.
     */
    public function invoke(callable $callable, array $parameters = []): mixed
    {
        $rFunction = $this->reflectCallable($callable);
        if (!$rFunction instanceof ReflectionMethod || $rFunction->getName() === '__construct') {
            return $this->baseInvoker->invoke($callable, $parameters);
        }

        $guards = $this->getInvocationGuards($rFunction);
        $interceptors = $this->getInterceptors($rFunction);

        foreach ($guards as $guard) {
            $this->baseInvoker->invoke([$guard, 'handle'], [
                ReflectionMethod::class => $rFunction,
            ]);
        }

        foreach ($interceptors as $interceptor) {
            $interceptor->preHandle($rFunction);
        }

        try {
            $return = $this->baseInvoker->invoke($callable, $parameters);
            foreach ($interceptors as $interceptor) {
                $interceptor->postHandle($rFunction, $return);
            }
            return $return;
        } catch (Throwable $e) {
            foreach (array_reverse($interceptors) as $interceptor) {
                $interceptor->onException($rFunction, $e);
            }
            throw $e;
        }
    }

    /**
     * Resolves guard attributes declared on the target method.
     *
     * @return list<GuardInterface>
     */
    protected function getInvocationGuards(ReflectionMethod $rMethod): array
    {
        $guards = [];

        $attributes = $rMethod->getAttributes(GuardInterface::class, ReflectionAttribute::IS_INSTANCEOF);
        foreach ($attributes as $attribute) {
            $guards[] = $this->container->make($attribute->getName(), $attribute->getArguments());
        }

        return $guards;
    }

    /**
     * Resolves interceptor attributes declared on the target method.
     *
     * @return list<InterceptorInterface>
     */
    protected function getInterceptors(ReflectionMethod $rMethod): array
    {
        $interceptors = [];

        $attributes = $rMethod->getAttributes(InterceptorInterface::class, ReflectionAttribute::IS_INSTANCEOF);
        foreach ($attributes as $attribute) {
            $interceptors[] = $this->container->make($attribute->getName(), $attribute->getArguments());
        }

        return $interceptors;
    }

    /**
     * Reflects the callable so method-only invocation hooks can be discovered.
     */
    protected function reflectCallable(callable $callable): ReflectionFunction|ReflectionMethod
    {
        if (is_array($callable)) {
            return new ReflectionMethod($callable[0], $callable[1]);
        }

        if (is_object($callable) && !$callable instanceof Closure) {
            return new ReflectionMethod($callable, '__invoke');
        }

        return new ReflectionFunction($callable);
    }
}
