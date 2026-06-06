<?php

declare(strict_types=1);

namespace Switon\Routing;

use ReflectionClass;
use Switon\Core\Naming;
use Switon\Routing\Attribute\MappingInterface;
use Switon\Routing\Attribute\RequestMapping;

use function basename;
use function is_array;
use function rtrim;
use function str_starts_with;

/**
 * Expands attribute path definitions into concrete route patterns.
 *
 * Use when registration needs deterministic path generation for class and method mappings.
 *
 * @see \Switon\Routing\MappingPathInterface::resolveMethodPattern()
 * @see \Switon\Routing\MappingPathInterface::resolveControllerPrefixes()
 * @see \Switon\Routing\RouteRegistrarInterface
 * @see \Switon\Routing\RouteRegistrar
 * @see \Switon\Routing\Attribute\MappingInterface
 * @see \Switon\Routing\Attribute\Mapping::getPath()
 * @see \Switon\Core\Naming::kebab()
 */
class MappingPath implements MappingPathInterface
{
    public function resolveControllerPrefixes(ReflectionClass $rClass, RequestMapping $requestMapping): array
    {
        $classPath = $requestMapping->getPath();
        if ($classPath === null) {
            $classPath = '/' . Naming::kebab(basename($rClass->getShortName(), 'Controller'));
        }

        return array_map(
            static fn (string $path): string => rtrim($path, '/'),
            is_array($classPath) ? $classPath : [$classPath]
        );
    }

    public function resolveMethodPatterns(array $prefixes, string $actionMethod, MappingInterface $mapping): array
    {
        $patterns = [];
        $path = $mapping->getPath();

        foreach (is_array($path) ? $path : [$path] as $pathItem) {
            foreach ($prefixes as $prefix) {
                $patterns[] = $this->resolveMethodPattern($prefix, $actionMethod, $pathItem);
            }
        }

        return $patterns;
    }

    public function resolveMethodPattern(string $prefix, string $actionMethod, ?string $pathItem): string
    {
        if ($pathItem === null) {
            return $prefix . '/' . Naming::kebab(basename($actionMethod, 'Action'));
        }

        if ($pathItem === '') {
            return $prefix;
        }

        if (str_starts_with($pathItem, '/')) {
            return $pathItem;
        }

        return $prefix . '/' . $pathItem;
    }

}
