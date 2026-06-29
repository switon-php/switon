<?php

declare(strict_types=1);

namespace Switon\Routing;

use ReflectionClass;
use ReflectionMethod;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Attributes;
use Switon\Core\ClassScannerInterface;
use Switon\Routing\Attribute\MappingInterface;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\Viewable;

/**
 * Discovers routing attributes from controller classes and emits concrete route definitions.
 *
 * Use when routing boot should resolve controller mappings before writing them into the router.
 *
 * Guidance: Keep class scanning in {@see ClassScannerInterface}; this class owns mapping resolution and Viewable expansion only.
 *
 * Road-signs:
 * - discover classes from configured entries
 * - resolve class-level RequestMapping
 * - resolve method-level MappingInterface attributes
 * - MappingPath expands prefixes and patterns
 * - Viewable may add GET siblings
 *
 * @see \Switon\Routing\MappingDiscoveryInterface
 * @see \Switon\Core\ClassScannerInterface
 * @see \Switon\Routing\MappingPathInterface
 */
class MappingDiscovery implements MappingDiscoveryInterface
{
    #[Autowired] protected ClassScannerInterface $classScanner;
    #[Autowired] protected MappingPathInterface $mappingPath;

    public function discover(array $entries): array
    {
        $routes = [];

        foreach ($this->classScanner->scan($entries, RequestMapping::class) as $className) {
            foreach ($this->discoverClass($className) as $route) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    public function discoverClass(string $className): array
    {
        if (!class_exists($className)) {
            return [];
        }

        $rClass = new ReflectionClass($className);
        $requestMapping = Attributes::first($rClass, RequestMapping::class);
        if ($requestMapping === null) {
            return [];
        }

        return $this->discoverController($rClass, $requestMapping);
    }

    /**
     * @param ReflectionClass<object> $rClass
     *
     * @return list<array{verb: string, pattern: string, handler: string}>
     */
    protected function discoverController(ReflectionClass $rClass, RequestMapping $requestMapping): array
    {
        $controller = $rClass->getName();
        $prefixes = $this->mappingPath->resolveControllerPrefixes($rClass, $requestMapping);
        $routes = [];

        foreach ($rClass->getMethods(ReflectionMethod::IS_PUBLIC) as $rMethod) {
            $method = $rMethod->getName();
            foreach ($this->resolveMethodMappings($rMethod) as $mapping) {
                foreach ($this->mappingPath->resolveMethodPatterns($prefixes, $method, $mapping) as $pattern) {
                    foreach ($this->buildRoutes($controller, $method, $pattern, $mapping) as $route) {
                        $routes[] = $route;
                    }
                }
            }
        }

        return $routes;
    }

    /**
     * @return list<\Switon\Routing\Attribute\MappingInterface>
     */
    protected function resolveMethodMappings(ReflectionMethod $rMethod): array
    {
        return Attributes::all($rMethod, MappingInterface::class);
    }

    /**
     * @return list<array{verb: string, pattern: string, handler: string}>
     */
    protected function buildRoutes(
        string           $controller,
        string           $method,
        string           $pattern,
        MappingInterface $mapping
    ): array {
        $handler = $controller . '::' . $method;
        $routes = [];

        if ($mapping instanceof Viewable && $mapping->getVerb() !== 'GET') {
            $routes[] = ['verb' => 'GET', 'pattern' => $pattern, 'handler' => $handler];
        }

        $routes[] = ['verb' => $mapping->getVerb(), 'pattern' => $pattern, 'handler' => $handler];

        return $routes;
    }
}
