<?php

declare(strict_types=1);

namespace Switon\Routing;

/**
 * Defines route registration from discovered controller mapping attributes.
 *
 * Use when loading RequestMapping/MappingInterface attributes into the router.
 *
 * Road-signs:
 * - MappingDiscoveryInterface discovers route definitions
 * - register() loads configured controller sources
 * - MappingPath expands prefixes and method paths
 * - Viewable adds GET when needed
 * - registerClass() bypasses file-pattern discovery
 *
 * @see \Switon\Routing\RouteRegistrar
 * @see \Switon\Routing\MappingDiscoveryInterface
 * @see \Switon\Routing\RouterInterface
 */
interface RouteRegistrarInterface
{
    /**
     * Registers routes from configured controller sources into the router.
     */
    public function register(): void;

    /**
     * Registers routes for one controller class into the router.
     */
    public function registerClass(string $className): void;
}
