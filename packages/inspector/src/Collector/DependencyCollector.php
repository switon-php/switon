<?php

declare(strict_types=1);

namespace Switon\Inspector\Collector;

use JsonSerializable;
use ReflectionClass;
use Switon\Core\{CollectorInterface, ContextManagerInterface, Lazy};
use Switon\Core\Attributes;
use Switon\Core\Attribute\Autowired;
use Switon\Di\ContainerInterface;
use Switon\Inspector\DumperInterface;
use Switon\Routing\Attribute\RequestMapping;

use function is_string;
use function spl_object_id;
use function str_contains;

/**
 * Collects resolved services and autowired property dependencies for inspector output.
 *
 * @see \Switon\Core\CollectorInterface
 * @see \Switon\Di\ContainerInterface
 */
class DependencyCollector implements CollectorInterface, JsonSerializable
{
    #[Autowired] protected ContainerInterface $container;
    #[Autowired] protected DumperInterface|Lazy $dumper;

    public function boot(): void
    {
    }

    public function collect(): array
    {
        $definitions = $this->container->getDefinitions();
        $dependencies = [];

        foreach ($this->container->getInstances() as $id => $instance) {
            if ($instance instanceof \Psr\Container\ContainerInterface) {
                continue;
            }

            // Skip collectors
            if ($instance instanceof CollectorInterface) {
                continue;
            }

            // Skip classes with RequestMapping attribute (controllers)
            $reflection = new ReflectionClass($instance);
            if (Attributes::has($reflection, RequestMapping::class)) {
                continue;
            }

            if (($definition = $definitions[$id] ?? null) !== null
                && is_string($definition)
                && str_contains($definition, '#')
            ) {
                $properties = $definition;
            } else {
                $allProperties = $this->dumper->dump($instance);

                // Filter to only include properties with #[Autowired] attribute
                $properties = $this->filterDependencyProperties($instance, $allProperties);
            }

            if ($instance instanceof ContextManagerInterface) {
                unset($properties['contexts']);
            }

            $dependencies[$id] = [
                'class' => $instance::class,
                'object_id' => spl_object_id($instance),
                'properties' => $properties
            ];
        }

        return $dependencies;
    }

    /**
     * Returns only <code>#[Autowired]</code> properties from dumped object state.
     *
     * @param array<string, mixed> $allProperties
     *
     * @return array<string, mixed>
     */
    protected function filterDependencyProperties(object $instance, array $allProperties): array
    {
        $filtered = [];
        $rf = new ReflectionClass($instance);

        foreach ($rf->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $name = $property->getName();
            if (Attributes::has($property, Autowired::class)) {
                if (isset($allProperties[$name])) {
                    $filtered[$name] = $allProperties[$name];
                }
            }
        }

        // Preserve context if object is ContextAware
        if (isset($allProperties['context'])) {
            $filtered['context'] = $allProperties['context'];
        }

        return $filtered;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [];
    }
}
