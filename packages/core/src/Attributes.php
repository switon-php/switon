<?php

declare(strict_types=1);

namespace Switon\Core;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Reflection attribute lookup helpers.
 *
 * Guidance: Matching uses <code>ReflectionAttribute::IS_INSTANCEOF</code>; use raw <code>getAttributes()</code> when exact class names are required.
 *
 * @see \ReflectionAttribute
 * @see \Switon\Core\Reflections
 */
class Attributes
{
    /**
     * Returns whether the reflector declares at least one matching attribute.
     *
     * @template TRef of object
     *
     * @param ReflectionClass<TRef>|ReflectionMethod|ReflectionProperty|ReflectionParameter $ref
     * @param class-string $name Attribute class name
     */
    public static function has(ReflectionClass|ReflectionMethod|ReflectionProperty|ReflectionParameter $ref, string $name): bool
    {
        return $ref->getAttributes($name, ReflectionAttribute::IS_INSTANCEOF) !== [];
    }

    /**
     * Returns the first matching attribute instance, or null when none is declared.
     *
     * @template T of object
     * @template TRef of object
     *
     * @param ReflectionClass<TRef>|ReflectionMethod|ReflectionProperty|ReflectionParameter $ref
     * @param class-string<T> $name Attribute class name
     *
     * @return T|null
     */
    public static function first(ReflectionClass|ReflectionMethod|ReflectionProperty|ReflectionParameter $ref, string $name): ?object
    {
        $attributes = $ref->getAttributes($name, ReflectionAttribute::IS_INSTANCEOF);
        if (($attribute = $attributes[0] ?? null) === null) {
            return null;
        }

        return $attribute->newInstance();
    }

    /**
     * Returns all matching attribute instances in declaration order.
     *
     * @template T of object
     * @template TRef of object
     *
     * @param ReflectionClass<TRef>|ReflectionMethod|ReflectionProperty|ReflectionParameter $ref
     * @param class-string<T> $name Attribute class name
     *
     * @return list<T>
     */
    public static function all(ReflectionClass|ReflectionMethod|ReflectionProperty|ReflectionParameter $ref, string $name): array
    {
        $instances = [];
        foreach ($ref->getAttributes($name, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $instances[] = $attribute->newInstance();
        }

        return $instances;
    }
}
