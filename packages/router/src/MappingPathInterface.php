<?php

declare(strict_types=1);

namespace Switon\Routing;

use ReflectionClass;
use Switon\Routing\Attribute\MappingInterface;
use Switon\Routing\Attribute\RequestMapping;

/**
 * Defines path expansion rules for attribute-based route registration.
 *
 * Use when converting class-level and method-level mapping paths into concrete router patterns.
 *
 * @see \Switon\Routing\MappingPath
 * @see \Switon\Routing\RouteRegistrarInterface
 * @see \Switon\Routing\RouteRegistrar
 */
interface MappingPathInterface
{
    /**
     * Expands class-level RequestMapping path into normalized controller prefixes.
     *
     * Rule summary:
     * - <code>null</code> => <code>/{kebab(controllerNameWithoutControllerSuffix)}</code>
     * - <code>''</code> => no prefix
     * - <code>'/absolute'</code> => use as provided
     * - <code>array</code> => one resolved prefix per item
     * - trailing <code>/</code> is trimmed from each resolved prefix
     *
     * @param ReflectionClass<object> $rClass
     * @param RequestMapping $requestMapping
     *
     * @return array<string>
     */
    public function resolveControllerPrefixes(ReflectionClass $rClass, RequestMapping $requestMapping): array;

    /**
     * Expands one method mapping into concrete route patterns under provided prefixes.
     *
     * @param array<string> $prefixes
     * @param string $actionMethod Controller action method name (for example: <code>showAction</code>)
     * @param MappingInterface $mapping
     *
     * @return array<string>
     */
    public function resolveMethodPatterns(array $prefixes, string $actionMethod, MappingInterface $mapping): array;

    /**
     * Resolves one concrete route pattern from prefix, action method name, and one path item.
     *
     * @param string $prefix
     * @param string $actionMethod Controller action method name (for example: <code>showAction</code>)
     * @param string|null $pathItem One item from mapping path configuration
     *
     * @return string
     */
    public function resolveMethodPattern(string $prefix, string $actionMethod, ?string $pathItem): string;
}
