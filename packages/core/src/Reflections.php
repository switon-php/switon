<?php

declare(strict_types=1);

namespace Switon\Core;

use Closure;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Switon\Core\Exception\InvalidArgumentException;
use function is_array;
use function is_object;
use function is_string;
use function is_subclass_of;
use function str_contains;

/**
 * Reflection helpers for callables, types, and object properties.
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

    /**
     * Return the first declared return type name for one method, or null when unavailable.
     */
    public static function getMethodReturnType(string $class, string $method): ?string
    {
        try {
            $rType = (new ReflectionMethod($class, $method))->getReturnType();
        } catch (\ReflectionException) {
            return null;
        }

        if ($rType === null || $rType instanceof ReflectionIntersectionType) {
            return null;
        }

        try {
            $typeNames = self::getTypeNames($rType);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $typeNames[0] ?? null;
    }

    /**
     * Find the first initialized property whose declared type matches {@see $typeName} and whose value is an instance of it.
     */
    public static function findPropertyValueByType(object $object, string $typeName): ?object
    {
        foreach ((new ReflectionClass($object))->getProperties() as $rProperty) {
            if (!self::propertyDeclaresType($rProperty, $typeName)) {
                continue;
            }

            if (!$rProperty->isInitialized($object)) {
                continue;
            }

            if (!$rProperty->isPublic()) {
                $rProperty->setAccessible(true);
            }

            $value = $rProperty->getValue($object);
            if ($value instanceof $typeName) {
                return $value;
            }
        }

        return null;
    }

    protected static function propertyDeclaresType(ReflectionProperty $rProperty, string $typeName): bool
    {
        $rType = $rProperty->getType();
        if ($rType === null || $rType instanceof ReflectionIntersectionType) {
            return false;
        }

        try {
            $declaredTypes = self::getTypeNames($rType);
        } catch (InvalidArgumentException) {
            return false;
        }

        foreach ($declaredTypes as $declaredType) {
            if ($declaredType === 'object') {
                continue;
            }

            if (self::declaredTypeMatches($declaredType, $typeName)) {
                return true;
            }
        }

        return false;
    }

    protected static function declaredTypeMatches(string $declaredType, string $typeName): bool
    {
        return $declaredType === $typeName
            || is_subclass_of($declaredType, $typeName)
            || is_subclass_of($typeName, $declaredType);
    }
}
