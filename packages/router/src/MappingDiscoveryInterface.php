<?php

declare(strict_types=1);

namespace Switon\Routing;

/**
 * Resolves controller mapping attributes into concrete route definitions.
 *
 * Use when router boot needs route candidates without mutating the router table yet.
 *
 * Road-signs:
 * - file/class discovery via ClassScannerInterface
 * - RequestMapping gates controller participation
 * - MappingPath expands controller prefixes and method paths
 * - Viewable may add extra GET routes
 *
 * @see \Switon\Routing\MappingDiscovery
 * @see \Switon\Core\ClassScannerInterface
 * @see \Switon\Routing\MappingPathInterface
 * @see \Switon\Routing\RouteRegistrarInterface
 */
interface MappingDiscoveryInterface
{
    /**
     * Discovers routes from configured controller sources.
     *
     * @param array<int|string, string> $entries Numeric keys: direct class names; string keys: glob => class pattern
     *
     * @return list<array{verb: string, pattern: string, handler: string}>
     */
    public function discover(array $entries): array;

    /**
     * Discovers routes for one controller class.
     *
     * Returns an empty list when the class does not exist or is not marked with <code>#[RequestMapping]</code>.
     *
     * @return list<array{verb: string, pattern: string, handler: string}>
     */
    public function discoverClass(string $className): array;
}
