<?php

declare(strict_types=1);

namespace Switon\Dict\Tests\Unit;

use Switon\Dict\Dict;
use Switon\Dict\DictEntry;
use Switon\Dict\DictReaderInterface;
use Switon\Dict\DictResolver;
use Switon\Dict\DictType;
use Switon\Dict\Exception\DictItemNotFoundException;
use Switon\Dict\Exception\DictTypeCodeRequiredException;
use Switon\Dict\Exception\DictTypeNotFoundException;
use Switon\Dict\Exception\UnsupportedDictParameterTypeException;
use Switon\Dict\Tests\Fixtures\CapturingAreaOrderController;
use Switon\Dict\Tests\Fixtures\CapturingOrderController;
use Switon\Dict\Tests\Support\ArrayInput;
use Switon\Dict\Tests\Support\BoundAction;
use ReflectionMethod;
use Switon\Dict\Tests\TestCase;
use Switon\Core\InputInterface;
use Switon\Validating\ValidatorInterface;

class DictResolverTest extends TestCase
{
    public function testResolveUsesExplicitTypeCode(): void
    {
        $dictReader = $this->createMock(DictReaderInterface::class);
        $dictReader->expects($this->once())
            ->method('getType')
            ->with('order.status', true)
            ->willReturn(new DictType('order.status', 'Order Status'));
        $dictReader->expects($this->once())
            ->method('getItem')
            ->with('order.status', 'paid', true)
            ->willReturn(new DictEntry('order.status', 'paid', 'Paid', 'paid'));

        $this->container->set(DictReaderInterface::class, $dictReader);

        $controller = new CapturingOrderController();
        BoundAction::invoke($this->container, $controller, 'updateAction', ['status' => 'paid']);

        $status = $controller->receivedStatus;
        $this->assertNotNull($status);
        $this->assertSame('order.status', $status->getTypeCode());
        $this->assertSame('paid', $status->getItemCode());
        $this->assertSame('Paid', $status->getItemName());
        $this->assertSame('paid', $status->getValue());
    }

    public function testResolveUsesTypeOwnedCodeWhenTypeCodeIsOmitted(): void
    {
        $dictReader = $this->createMock(DictReaderInterface::class);
        $dictReader->expects($this->once())
            ->method('getType')
            ->with('order.status', true)
            ->willReturn(new DictType('order.status', 'Order Status'));
        $dictReader->expects($this->once())
            ->method('getItem')
            ->with('order.status', 'paid', true)
            ->willReturn(new DictEntry('order.status', 'paid', 'Paid', 'paid'));

        $this->container->set(DictReaderInterface::class, $dictReader);

        $controller = new CapturingAreaOrderController();
        BoundAction::invoke($this->container, $controller, 'updateAction', ['status' => 'paid']);

        $status = $controller->receivedStatus;
        $this->assertNotNull($status);
        $this->assertSame('order.status', $status->getTypeCode());
        $this->assertSame('paid', $status->getValue());
    }

    public function testResolveUsesDefaultItemWhenValueIsEmpty(): void
    {
        $dictReader = $this->createMock(DictReaderInterface::class);
        $dictReader->expects($this->once())
            ->method('getType')
            ->with('order.status', true)
            ->willReturn(new DictType('order.status', 'Order Status'));
        $dictReader->expects($this->never())->method('getItem');
        $dictReader->expects($this->once())
            ->method('getDefaultItem')
            ->with('order.status', true)
            ->willReturn(new DictEntry('order.status', 'paid', 'Paid', 'paid'));

        $this->container->set(DictReaderInterface::class, $dictReader);

        $controller = new CapturingOrderController();
        BoundAction::invoke($this->container, $controller, 'updateAction', ['status' => '']);

        $status = $controller->receivedStatus;
        $this->assertNotNull($status);
        $this->assertSame('paid', $status->getItemCode());
        $this->assertSame('paid', $status->getValue());
    }

    public function testResolveReturnsNullForNullableParameterWhenValueIsEmpty(): void
    {
        $dictReader = $this->createMock(DictReaderInterface::class);
        $dictReader->expects($this->once())
            ->method('getType')
            ->with('order.status', true)
            ->willReturn(new DictType('order.status', 'Order Status'));
        $dictReader->expects($this->never())->method('getItem');
        $dictReader->expects($this->never())->method('getDefaultItem');

        $this->container->set(DictReaderInterface::class, $dictReader);

        $controller = new CapturingOrderController();
        BoundAction::invoke($this->container, $controller, 'nullableAction', ['status' => '']);

        $this->assertNull($controller->receivedStatus);
    }

    public function testResolveThrowsExceptionWhenTypeCodeCannotBeDerived(): void
    {
        $dictReader = $this->createMock(DictReaderInterface::class);
        $dictReader->expects($this->never())->method('getType');

        $this->container->set(DictReaderInterface::class, $dictReader);

        $controller = new class () {
            public function updateAction(DictResolverMissingTypeCodeFixture $status): void
            {
            }
        };

        $this->expectException(DictTypeCodeRequiredException::class);
        $this->expectExceptionMessage('Unable to derive dict type');

        BoundAction::invoke($this->container, $controller, 'updateAction', ['status' => 'paid']);
    }

    public function testResolveThrowsWhenParameterTypeIsNotDictSubclass(): void
    {
        $dictReader = $this->createMock(DictReaderInterface::class);
        $dictReader->expects($this->never())->method('getType');

        $this->container->set(DictReaderInterface::class, $dictReader);
        $this->container->set(InputInterface::class, new ArrayInput());

        $resolver = $this->make(DictResolver::class);
        $controller = new CapturingOrderController();

        $this->expectException(UnsupportedDictParameterTypeException::class);
        $this->expectExceptionMessage('DictResolver only supports');

        $parameter = (new ReflectionMethod($controller, 'updateAction'))->getParameters()[0];
        $resolver->resolve($parameter, stdClass::class);
    }

    public function testResolveThrowsWhenDictTypeDoesNotExist(): void
    {
        $dictReader = $this->createMock(DictReaderInterface::class);
        $dictReader->expects($this->once())
            ->method('getType')
            ->with('order.status', true)
            ->willReturn(null);
        $dictReader->expects($this->never())->method('getItem');

        $this->container->set(DictReaderInterface::class, $dictReader);

        $this->expectException(DictTypeNotFoundException::class);
        $this->expectExceptionMessage('order.status');

        BoundAction::invoke($this->container, new CapturingOrderController(), 'updateAction', ['status' => 'paid']);
    }

    public function testResolveThrowsWhenItemLookupFailsAfterValidationPasses(): void
    {
        $dictReader = $this->createMock(DictReaderInterface::class);
        $dictReader->expects($this->once())
            ->method('getType')
            ->willReturn(new DictType('order.status', 'Order Status'));
        $dictReader->expects($this->once())
            ->method('getItem')
            ->with('order.status', 'missing', true)
            ->willReturn(null);

        $validation = $this->createMock(\Switon\Validating\Validation::class);
        $validation->expects($this->once())->method('validate')->willReturn(true);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->once())->method('beginValidate')->willReturn($validation);
        $validator->expects($this->once())->method('endValidate');

        $this->container->set(DictReaderInterface::class, $dictReader);
        $this->container->set(ValidatorInterface::class, $validator);

        $this->expectException(DictItemNotFoundException::class);
        $this->expectExceptionMessage('did not resolve');

        BoundAction::invoke($this->container, new CapturingOrderController(), 'updateAction', ['status' => 'missing']);
    }
}

class DictResolverMissingTypeCodeFixture extends Dict
{
    public static function typeCode(): ?string
    {
        return '';
    }
}
