<?php

declare(strict_types=1);

namespace Switon\Core;

use Closure;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Switon\Core\Exception\InvalidArgumentException;
use function is_array;
use function is_object;
use function is_string;
use function str_contains;

/**
 * Callable reflection helpers.
 *
 * @see \ReflectionFunctionAbstract
 */
class Reflections
{
    public static function getCallable(callable $handler): ReflectionFunctionAbstract
    {
        if (is_array($handler) && isset($handler[0], $handler[1])) {
            return new ReflectionMethod($handler[0], $handler[1]);
        }

        if (is_string($handler) && str_contains($handler, '::')) {
            [$class, $method] = explode('::', $handler, 2);

            return new ReflectionMethod($class, $method);
        }

        if ($handler instanceof Closure) {
            return new ReflectionFunction($handler);
        }

        if (is_object($handler)) {
            return new ReflectionMethod($handler, '__invoke');
        }

        if (is_string($handler)) {
            return new ReflectionFunction($handler);
        }

        InvalidArgumentException::raise(
            'Unsupported callable handler "{handler}".',
            ['handler' => self::describeCallable($handler)]
        );
    }

    public static function describeCallable(callable $handler): string
    {
        if (is_array($handler) && isset($handler[0], $handler[1])) {
            $target = $handler[0];

            return (is_object($target) ? $target::class : (string)$target) . '::' . $handler[1];
        }

        if (is_string($handler)) {
            return $handler;
        }

        if ($handler instanceof Closure) {
            return 'Closure';
        }

        if (is_object($handler)) {
            return $handler::class . '::__invoke';
        }

        return 'callable';
    }

    /**
     * @return list<string> Declared type names (union members flattened).
     */
    public static function getTypeNames(ReflectionType $type): array
    {
        if ($type instanceof ReflectionIntersectionType) {
            InvalidArgumentException::raise('Intersection types are not supported.');
        }

        if ($type instanceof ReflectionUnionType) {
            $names = [];
            foreach ($type->getTypes() as $member) {
                if (!$member instanceof ReflectionNamedType) {
                    InvalidArgumentException::raise('Unsupported reflection type.');
                }
                $names[] = $member->getName();
            }

            return $names;
        }

        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }

        InvalidArgumentException::raise('Unsupported reflection type.');
    }
}
