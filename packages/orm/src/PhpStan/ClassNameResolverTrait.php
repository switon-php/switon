<?php

declare(strict_types=1);

namespace Switon\Orm\PhpStan;

use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use ReflectionClass;
use Switon\Core\Attributes;
use Switon\Orm\Attribute\Repository as RepositoryAttribute;

use function class_exists;
use function interface_exists;
use function preg_match;

trait ClassNameResolverTrait
{
    private function resolveClassType(Type $type): ?Type
    {
        $classNames = $type->getObjectClassNames();
        if ($classNames !== []) {
            return $this->resolveExistingClassName($classNames[0]);
        }

        $strings = $type->getConstantStrings();
        if ($strings === []) {
            return null;
        }

        return $this->resolveExistingClassName($strings[0]->getValue());
    }

    private function resolveExistingClassName(string $className): ?Type
    {
        if (class_exists($className) || interface_exists($className)) {
            return new ObjectType($className);
        }

        return null;
    }

    private function resolveRepositoryClassName(string $entityClass): ?string
    {
        if (!class_exists($entityClass)) {
            return null;
        }

        $reflection = new ReflectionClass($entityClass);
        $instance = Attributes::first($reflection, RepositoryAttribute::class);
        if ($instance !== null) {
            return $instance->name;
        }

        if (preg_match('#^(.*)\\\\Entity\\\\(\\w+)$#', $entityClass, $match) === 1) {
            return $match[1] . '\\Repository\\' . $match[2] . 'Repository';
        }

        return null;
    }
}
