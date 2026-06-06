<?php

declare(strict_types=1);

namespace Switon\Invocation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Switon\Invocation\Attribute\Interceptor;
use Switon\Invocation\Attribute\InterceptorInterface;
use Error;
use RuntimeException;

class InterceptorTest extends TestCase
{
    public function testPostHandleParameterIsPassedByReferenceInContract(): void
    {
        $interfaceMethod = new ReflectionMethod(\Switon\Invocation\Attribute\InterceptorInterface::class, 'postHandle');
        $classMethod = new ReflectionMethod(Interceptor::class, 'postHandle');

        $this->assertTrue($interfaceMethod->getParameters()[1]->isPassedByReference());
        $this->assertTrue($classMethod->getParameters()[1]->isPassedByReference());
    }

    public function testPreHandleReturnsTrueByDefault(): void
    {
        $interceptor = new Interceptor();
        $method = new ReflectionMethod($this, __FUNCTION__);

        $this->assertTrue($interceptor->preHandle($method));
    }

    public function testPostHandleDoesNothingByDefault(): void
    {
        $interceptor = new Interceptor();
        $method = new ReflectionMethod($this, __FUNCTION__);
        $return = 'test return';

        $interceptor->postHandle($method, $return);

        $this->assertSame('test return', $return);
    }

    public function testOnExceptionDoesNothingByDefault(): void
    {
        $interceptor = new Interceptor();
        $method = new ReflectionMethod($this, __FUNCTION__);

        $interceptor->onException($method, new RuntimeException('boom'));

        $this->assertTrue(true);
    }

    public function testOnExceptionAcceptsErrorThrowableByContract(): void
    {
        $interceptor = new Interceptor();
        $method = new ReflectionMethod($this, __FUNCTION__);

        $interceptor->onException($method, new Error('fatal'));

        $this->addToAssertionCount(1);
    }

    public function testExtendedInterceptorCanShortCircuitViaPreHandle(): void
    {
        $interceptor = new class () extends Interceptor {
            public function preHandle(ReflectionMethod $method): bool
            {
                return false;
            }
        };
        $method = new ReflectionMethod($this, __FUNCTION__);

        $this->assertFalse($interceptor->preHandle($method));
    }

    public function testExtendedInterceptorPostHandleCanMutateReturnByReference(): void
    {
        $interceptor = new class () extends Interceptor {
            public function postHandle(ReflectionMethod $method, mixed &$return): void
            {
                $return = 'wrapped:' . (string)$return;
            }
        };
        $method = new ReflectionMethod($this, __FUNCTION__);
        $return = 'core';

        $interceptor->postHandle($method, $return);

        $this->assertSame('wrapped:core', $return);
    }

    public function testInterceptorClassImplementsInterceptorInterface(): void
    {
        $interceptor = new Interceptor();

        $this->assertInstanceOf(InterceptorInterface::class, $interceptor);
    }

    public function testPreHandleParameterIsNotPassedByReference(): void
    {
        $interfaceMethod = new ReflectionMethod(InterceptorInterface::class, 'preHandle');
        $classMethod = new ReflectionMethod(Interceptor::class, 'preHandle');

        $this->assertFalse($interfaceMethod->getParameters()[0]->isPassedByReference());
        $this->assertFalse($classMethod->getParameters()[0]->isPassedByReference());
    }
}
