<?php

declare(strict_types=1);

namespace Switon\Invoking\Tests\Unit\PhpStan;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Type;
use PHPUnit\Framework\TestCase;
use Switon\Invoking\InvokerInterface;
use Switon\Invoking\PhpStan\InvokerInvokeMethodReturnTypeExtension;

class InvokerInvokeMethodReturnTypeExtensionTest extends TestCase
{
    private InvokerInvokeMethodReturnTypeExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new InvokerInvokeMethodReturnTypeExtension();
    }

    public function testGetClassAndMethodSupport(): void
    {
        $this->assertSame(InvokerInterface::class, $this->extension->getClass());

        $invoke = $this->createStub(MethodReflection::class);
        $invoke->method('getName')->willReturn('invoke');
        $other = $this->createStub(MethodReflection::class);
        $other->method('getName')->willReturn('call');

        $this->assertTrue($this->extension->isMethodSupported($invoke));
        $this->assertFalse($this->extension->isMethodSupported($other));
    }

    public function testGetTypeFromMethodCallReturnsNullWithoutCallableArgument(): void
    {
        $method = $this->createStub(MethodReflection::class);
        $call = $this->createStub(MethodCall::class);
        $call->method('getArgs')->willReturn([]);
        $scope = $this->createStub(Scope::class);

        $this->assertNull($this->extension->getTypeFromMethodCall($method, $call, $scope));
    }

    public function testGetTypeFromMethodCallReturnsNullWhenCallableHasNoAcceptors(): void
    {
        $method = $this->createStub(MethodReflection::class);
        $call = $this->createStub(MethodCall::class);
        $call->method('getArgs')->willReturn([new Arg(new Variable('fn'))]);
        $scope = $this->createStub(Scope::class);
        $callableType = $this->createStub(Type::class);
        $callableType->method('getCallableParametersAcceptors')->willReturn([]);
        $scope->method('getType')->willReturn($callableType);

        $this->assertNull($this->extension->getTypeFromMethodCall($method, $call, $scope));
    }
}
