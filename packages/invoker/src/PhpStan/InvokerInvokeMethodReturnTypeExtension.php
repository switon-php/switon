<?php

declare(strict_types=1);

namespace Switon\Invoking\PhpStan;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;
use Switon\Invoking\InvokerInterface;

/**
 * Infers <code>InvokerInterface::invoke()</code> return type from the passed callable.
 */
final class InvokerInvokeMethodReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /** {@inheritDoc} */
    public function getClass(): string
    {
        return InvokerInterface::class;
    }

    /** {@inheritDoc} */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'invoke';
    }

    /** {@inheritDoc} */
    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        $args = $methodCall->getArgs();
        if (!isset($args[0])) {
            return null;
        }

        $callableType = $scope->getType($args[0]->value);
        $acceptors = $callableType->getCallableParametersAcceptors($scope);
        if ($acceptors === []) {
            return null;
        }

        return ParametersAcceptorSelector::combineAcceptors($acceptors)->getReturnType();
    }
}
