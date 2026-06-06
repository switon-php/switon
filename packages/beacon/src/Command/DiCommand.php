<?php

declare(strict_types=1);

namespace Switon\Beacon\Command;

use Psr\Container\ContainerInterface;
use Switon\Command\Attribute\Hidden;
use Switon\Command\Attribute\Tool;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClassName;
use Switon\Core\ConsoleInterface;
use Switon\Core\Json;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

use function class_exists;
use function get_debug_type;
use function interface_exists;
use function is_object;

/**
 * Inspect DI binding and autowired scalar config for a class or interface.
 *
 * When the argument is a class, prefers the matching interface: tries same-namespace
 * then {namespace}\Contract\{shortName}Interface (Switon convention).
 *
 * @see \Switon\Core\ContainerInterface Binding source
 * @see \Switon\Core\Attribute\Autowired Scalar config source
 * @see \Switon\Command\Attribute\Tool Tool entrypoint
 */
class DiCommand
{
    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected ContainerInterface $container;

    /**
     * Show binding: resolved class and #[Autowired] scalar/array properties with current values.
     * Output is always JSON.
     *
     * @param string $target Class or interface FQCN
     */
    #[Hidden, Tool('<class|interface>. Returns JSON: id, class, autowired[] → name, type, value. Prefers interface when class has one.')]
    public function bindingAction(string $target): int
    {
        $target = ClassName::normalize($target);
        $id = $this->resolveId($target);
        if (!$this->container->has($id)) {
            $this->console->writeLn(Json::stringify(['error' => 'not bound', 'id' => $id]));
            return 1;
        }

        try {
            $instance = $this->container->get($id);
        } catch (Throwable $e) {
            $this->console->writeLn(Json::stringify([
                'error' => $e->getMessage(),
                'id' => $id,
            ]));
            return 1;
        }

        $class = is_object($instance) ? $instance::class : get_debug_type($instance);
        $autowired = $this->collectAutowiredScalar($instance);

        $payload = [
            'id' => $id,
            'class' => $class,
            'autowired' => $autowired,
        ];
        $this->console->writeLn(Json::stringify($payload));
        return 0;
    }

    /**
     * Resolve which id to use for container->get(). For a class: prefer its single implemented interface
     * ending in Interface (and bound); else try same-namespace then Contract sub-namespace.
     */
    protected function resolveId(string $target): string
    {
        $target = ClassName::normalize($target);
        if (str_ends_with($target, 'Interface')) {
            return $target;
        }
        if (class_exists($target)) {
            $implemented = class_implements($target);
            $ifaces = $implemented !== false
                ? array_filter(
                    array_keys($implemented),
                    static fn (string $name) => str_ends_with($name, 'Interface')
                )
                : [];
            if (count($ifaces) === 1) {
                $single = array_first($ifaces);
                if ($this->container->has($single)) {
                    return $single;
                }
            }
        }
        $iface = $target . 'Interface';
        if (interface_exists($iface) && $this->container->has($iface)) {
            return $iface;
        }
        $pos = strrpos($target, '\\');
        $namespace = $pos !== false ? substr($target, 0, $pos) : '';
        $shortName = $pos !== false ? substr($target, $pos + 1) : $target;
        $contractIface = $namespace . '\\Contract\\' . $shortName . 'Interface';
        if (interface_exists($contractIface) && $this->container->has($contractIface)) {
            return $contractIface;
        }
        return $target;
    }

    /**
     * @return list<array{name: string, type: string, value: mixed}>
     */
    protected function collectAutowiredScalar(object $instance): array
    {
        $out = [];
        $seen = [];
        $ref = new ReflectionClass($instance);

        while ($ref !== false) {
            foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $p) {
                if (isset($seen[$p->getName()])) {
                    continue;
                }
                if ($p->getAttributes(Autowired::class) === []) {
                    continue;
                }
                $seen[$p->getName()] = true;

                $type = $p->getType();
                if ($type === null || !$this->isScalarOrArrayType($type)) {
                    continue;
                }

                $typeStr = $this->typeToString($type);
                $value = $p->getValue($instance);
                $out[] = [
                    'name' => $p->getName(),
                    'type' => $typeStr,
                    'value' => $value,
                ];
            }
            $ref = $ref->getParentClass() ?: false;
        }

        return $out;
    }

    protected function isScalarOrArrayType(ReflectionType $type): bool
    {
        $scalarOrArray = ['int', 'float', 'string', 'bool', 'array'];
        if ($type instanceof ReflectionNamedType) {
            return in_array($type->getName(), $scalarOrArray, true);
        }
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $t) {
                if (!in_array($t->getName(), $scalarOrArray, true)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    protected function typeToString(ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }
        if ($type instanceof ReflectionUnionType) {
            $parts = [];
            foreach ($type->getTypes() as $t) {
                $parts[] = $t->getName();
            }
            return implode('|', $parts);
        }
        return 'mixed';
    }
}
