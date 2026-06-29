<?php

declare(strict_types=1);

namespace Switon\Inspector;

use JsonSerializable;
use ReflectionClass;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Attributes;
use Switon\Core\ContextAware;
use Switon\Core\ContextManagerInterface;
use WeakMap;

use function class_implements;
use function is_array;
use function is_object;
use function is_string;
use function str_replace;
use function strlen;
use function substr;

/**
 * Extracts and normalizes object properties for inspector display.
 *
 * Use when collector payloads include objects that must be serialized safely.
 *
 * @see \Switon\Inspector\DumperInterface
 */
class Dumper implements DumperInterface
{
    #[Autowired] protected ContextManagerInterface $contextManager;

    /** {@inheritDoc} */
    public function getProperties(object $object): array
    {
        $data = [];
        $rf = new ReflectionClass($object);
        foreach ($rf->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $name = $property->getName();
            $hasInstances = false;
            if (($autowiredAttribute = Attributes::first($property, Autowired::class)) !== null) {
                $hasInstances = $autowiredAttribute->isInstances();
            }

            if ($property->isInitialized($object)) {
                $value = $property->getValue($object);
            } else {
                $value = null;
            }

            // Keep instance arrays readable in inspector output.
            if ($hasInstances && is_array($value)) {
                $simplified = [];
                foreach ($value as $key => $item) {
                    if (is_object($item)) {
                        $simplified[$key] = $item::class;
                    } else {
                        $simplified[$key] = $item;
                    }
                }
                $value = $simplified;
            } elseif ($value instanceof WeakMap) {
                $map = [];
                foreach ($value as $k => $v) {
                    $map[$k::class] = $v;
                }
                $value = $map;
            } elseif (is_object($value)) {
                // For object values, show class name instead of full dump
                $value = $value::class;
            }

            $data[$name] = $value;
        }

        if ($object instanceof ContextAware) {
            $data['context'] = (array)$this->contextManager->getContext($object);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    protected function normalize(array $properties): array
    {
        foreach ($properties as $name => $value) {
            if ($value instanceof WeakMap) {
                $value = (array)$value;
            }

            if (is_string($value)) {
                $value = str_replace('</script>', '[/script]', $value);

                if (strlen($value) > 128) {
                    $value = substr($value, 0, 128) . '...';
                }
            } elseif (is_object($value)) {
                if ($value instanceof JsonSerializable) {
                    $value = $this->normalize($value->jsonSerialize());
                } else {
                    $value = class_implements($value) === [] ? $this->normalize((array)$value)
                        : $value::class;
                }
            } elseif (is_array($value)) {
                $value = $this->normalize($value);
            }
            $properties[$name] = $value;
        }
        return $properties;
    }

    /** {@inheritDoc} */
    public function dump(object $object): array
    {
        $properties = $this->getProperties($object);

        return $this->normalize($properties);
    }
}
