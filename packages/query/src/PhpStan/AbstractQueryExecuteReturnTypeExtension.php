<?php

declare(strict_types=1);

namespace Switon\Query\PhpStan;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use Switon\Query\AbstractQuery;

/**
 * Infers {@see AbstractQuery::execute()} row list shape (sharded queries may use string keys).
 */
final class AbstractQueryExecuteReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function getClass(): string
    {
        return AbstractQuery::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'execute';
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): Type
    {
        return new ArrayType(
            new UnionType([new IntegerType(), new StringType()]),
            new ArrayType(new StringType(), new MixedType()),
        );
    }
}
