<?php

declare(strict_types=1);

namespace Switon\Core\Tests\Unit;

use ReflectionMethod;
use Switon\Core\Reflections;
use Switon\Core\Tests\TestCase;
use Countable;
use Traversable;

final class ReflectionsTest extends TestCase
{
    public function testGetCallableReflectsObjectMethodPair(): void
    {
        $handler = new ReflectionsTestHandler();

        $reflection = Reflections::getCallable([$handler, 'onEvent']);

        $this->assertInstanceOf(ReflectionMethod::class, $reflection);
        $this->assertSame(ReflectionsTestHandler::class, $reflection->getDeclaringClass()->getName());
        $this->assertSame('onEvent', $reflection->getName());
    }

    public function testGetCallableReflectsInvokableObject(): void
    {
        $reflection = Reflections::getCallable(new ReflectionsTestInvokable());

        $this->assertSame('__invoke', $reflection->getName());
    }

    public function testDescribeCallableNormalizesHandlerLabels(): void
    {
        $handler = new ReflectionsTestHandler();

        $this->assertSame(
            ReflectionsTestHandler::class . '::onEvent',
            Reflections::describeCallable([$handler, 'onEvent'])
        );
        $this->assertSame('Closure', Reflections::describeCallable(static function (): void {
        }));
        $this->assertSame(
            ReflectionsTestInvokable::class . '::__invoke',
            Reflections::describeCallable(new ReflectionsTestInvokable())
        );
    }

    public function testGetTypeNamesReturnsNamedType(): void
    {
        $type = (new ReflectionMethod(ReflectionsTestHandler::class, 'onEvent'))->getParameters()[0]->getType();

        $this->assertSame(['object'], Reflections::getTypeNames($type));
    }

    public function testGetTypeNamesFlattensUnionTypes(): void
    {
        $type = (new ReflectionMethod(ReflectionsTestUnionHandler::class, 'onEvent'))->getParameters()[0]->getType();

        $this->assertSame(
            [ReflectionsTestEventA::class, ReflectionsTestEventB::class],
            Reflections::getTypeNames($type)
        );
    }

    public function testGetTypeNamesReturnsEmptyListForIntersectionType(): void
    {
        $type = (new ReflectionMethod(ReflectionsTestIntersectionHandler::class, 'onEvent'))->getParameters()[0]->getType();

        $this->assertSame([], Reflections::getTypeNames($type));
    }

    public function testGetMethodReturnTypeReturnsFirstDeclaredType(): void
    {
        $this->assertSame(
            'string',
            Reflections::getMethodReturnType(ReflectionsTestReturnType::class, 'label')
        );
        $this->assertNull(Reflections::getMethodReturnType(self::class, 'missingMethod'));
    }

    public function testFindPropertyValueByTypeReturnsMatchingInitializedProperty(): void
    {
        $client = new ReflectionsTestClient();
        $holder = new ReflectionsTestClientHolder($client);

        $this->assertSame($client, Reflections::findPropertyValueByType($holder, ReflectionsTestClient::class));
        $this->assertNull(Reflections::findPropertyValueByType(new ReflectionsTestEmptyHolder(), ReflectionsTestClient::class));
    }
}

final class ReflectionsTestReturnType
{
    public function label(): string
    {
        return 'ok';
    }
}

final class ReflectionsTestClient
{
}

final class ReflectionsTestClientHolder
{
    public function __construct(protected ReflectionsTestClient $client)
    {
    }
}

final class ReflectionsTestEmptyHolder
{
    public string $name = 'empty';
}

final class ReflectionsTestEventA
{
}

final class ReflectionsTestEventB
{
}

final class ReflectionsTestUnionHandler
{
    public function onEvent(ReflectionsTestEventA|ReflectionsTestEventB $event): void
    {
    }
}

final class ReflectionsTestIntersectionHandler
{
    public function onEvent(Traversable&Countable $event): void
    {
    }
}

final class ReflectionsTestHandler
{
    public function onEvent(object $event): void
    {
    }
}

final class ReflectionsTestInvokable
{
    public function __invoke(object $event): void
    {
    }
}
