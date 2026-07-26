<?php

declare(strict_types=1);

namespace Switon\Query\PhpStan;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use Switon\Sharding\ShardingManagerInterface;

/**
 * Allows {@see ShardingManagerInterface::multiple()} / {@see ShardingManagerInterface::all()}
 * to accept {@see Table} or connection table name strings.
 */
class ShardingManagerTableArgumentMethodsClassReflectionExtension implements MethodsClassReflectionExtension
{
    protected const array METHODS = [
        'multiple' => true,
        'all' => true,
    ];

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $classReflection->getName() === ShardingManagerInterface::class
            && isset(self::METHODS[$methodName])
            && $classReflection->hasNativeMethod($methodName);
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return new ShardingManagerWidenedMethodReflection($classReflection->getNativeMethod($methodName));
    }
}
