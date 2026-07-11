<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionMethod;
use Switon\Binding\ArgumentsBinder;
use Switon\Core\InputInterface;
use Switon\Di\Container as DiContainer;

/**
 * Invokes controller-style actions through the real binding stack (reflection stays here).
 */
final class BoundAction
{
    /**
     * @param class-string|object $target
     * @param array<string, mixed> $input
     *
     * @return list<mixed>
     */
    public static function resolveArguments(
        DiContainer $container,
        object|string $target,
        string $method,
        array $input = [],
    ): array {
        self::prepareContainer($container, $input);

        $instance = is_string($target) ? $container->make($target) : $target;
        $binder = $container->make(ArgumentsBinder::class);

        return $binder->resolve(new ReflectionMethod($instance, $method));
    }

    /**
     * @param class-string|object $target
     * @param array<string, mixed> $input
     */
    public static function invoke(
        DiContainer $container,
        object|string $target,
        string $method,
        array $input = [],
    ): mixed {
        $instance = is_string($target) ? $container->make($target) : $target;
        $arguments = self::resolveArguments($container, $instance, $method, $input);

        return $instance->$method(...$arguments);
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function prepareContainer(DiContainer $container, array $input): void
    {
        if ($input !== [] || !$container->has(InputInterface::class)) {
            $container->set(InputInterface::class, new ArrayInput($input));
        }

        if (!$container->has(EventDispatcherInterface::class)) {
            $container->set(
                EventDispatcherInterface::class,
                new class () implements EventDispatcherInterface {
                    public function dispatch(object $event): object
                    {
                        return $event;
                    }
                },
            );
        }
    }
}
