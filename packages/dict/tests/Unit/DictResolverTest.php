<?php

declare(strict_types=1);

namespace Switon\Dict\Tests\Unit;

use ReflectionMethod;
use Switon\Binding\ScalarResolverInterface;
use Switon\Dict\Dict;
use Switon\Dict\DictEntry;
use Switon\Dict\DictReaderInterface;
use Switon\Dict\DictResolver;
use Switon\Dict\DictType;
use Switon\Dict\Exception\DictItemNotFoundException;
use Switon\Dict\Exception\DictTypeCodeRequiredException;
use Switon\Dict\Exception\DictTypeNotFoundException;
use Switon\Dict\Exception\UnsupportedDictParameterTypeException;
use Switon\Dict\Tests\Fixtures\App\Areas\Order\Controller\OrderController as AreaOrderController;
use Switon\Dict\Tests\Fixtures\OrderController;
use Switon\Dict\Tests\TestCase;
use Switon\Validating\ValidatorInterface;
use stdClass;

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

        $scalarResolver = $this->createMock(ScalarResolverInterface::class);
        $scalarResolver->expects($this->once())
            ->method('resolve')
            ->willReturn('paid');

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->never())->method('beginValidate');
        $validator->expects($this->never())->method('endValidate');

        /** @var DictResolver $resolver */
        $resolver = $this->make(DictResolver::class, [
            'dictReader' => $dictReader,
            'scalarResolver' => $scalarResolver,
            'validator' => $validator,
        ]);

        $parameter = (new ReflectionMethod(OrderController::class, 'updateAction'))->getParameters()[0];
        $dict = $resolver->resolve($parameter, $parameter->getType()?->getName() ?? '');

        $this->assertSame('order.status', $dict->getTypeCode());
        $this->assertSame('paid', $dict->getItemCode());
        $this->assertSame('Paid', $dict->getItemName());
        $this->assertSame('paid', $dict->getValue());
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

        $scalarResolver = $this->createMock(ScalarResolverInterface::class);
        $scalarResolver->expects($this->once())
            ->method('resolve')
            ->willReturn('paid');

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->never())->method('beginValidate');
        $validator->expects($this->never())->method('endValidate');

        /** @var DictResolver $resolver */
        $resolver = $this->make(DictResolver::class, [
            'dictReader' => $dictReader,
            'scalarResolver' => $scalarResolver,
            'validator' => $validator,
        ]);

        $parameter = (new ReflectionMethod(AreaOrderController::class, 'updateAction'))->getParameters()[0];
        $dict = $resolver->resolve($parameter, $parameter->getType()?->getName() ?? '');

        $this->assertSame('order.status', $dict->getTypeCode());
        $this->assertSame('paid', $dict->getValue());
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

        $scalarResolver = $this->createMock(ScalarResolverInterface::class);
        $scalarResolver->expects($this->once())
            ->method('resolve')
            ->willReturn('');

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->never())->method('beginValidate');
        $validator->expects($this->never())->method('endValidate');

        /** @var DictResolver $resolver */
        $resolver = $this->make(DictResolver::class, [
            'dictReader' => $dictReader,
            'scalarResolver' => $scalarResolver,
            'validator' => $validator,
        ]);

        $parameter = (new ReflectionMethod(OrderController::class, 'updateAction'))->getParameters()[0];
        $dict = $resolver->resolve($parameter, $parameter->getType()?->getName() ?? '');

        $this->assertSame('paid', $dict->getItemCode());
        $this->assertSame('paid', $dict->getValue());
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

        $scalarResolver = $this->createMock(ScalarResolverInterface::class);
        $scalarResolver->expects($this->once())
            ->method('resolve')
            ->willReturn('');

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->never())->method('beginValidate');
        $validator->expects($this->never())->method('endValidate');

        /** @var DictResolver $resolver */
        $resolver = $this->make(DictResolver::class, [
            'dictReader' => $dictReader,
            'scalarResolver' => $scalarResolver,
            'validator' => $validator,
        ]);

        $parameter = (new ReflectionMethod(OrderController::class, 'nullableAction'))->getParameters()[0];

        $this->assertNull($resolver->resolve($parameter, $parameter->getType()?->getName() ?? ''));
    }

    public function testResolveThrowsExceptionWhenTypeCodeCannotBeDerived(): void
    {
        $dictReader = $this->createMock(DictReaderInterface::class);
        $dictReader->expects($this->never())->method('getType');
        $dictReader->expects($this->never())->method('getItem');
        $dictReader->expects($this->never())->method('getDefaultItem');

        $scalarResolver = $this->createMock(ScalarResolverInterface::class);
        $scalarResolver->expects($this->never())->method('resolve');

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->never())->method('beginValidate');
        $validator->expects($this->never())->method('endValidate');

        /** @var DictResolver $resolver */
        $resolver = $this->make(DictResolver::class, [
            'dictReader' => $dictReader,
            'scalarResolver' => $scalarResolver,
            'validator' => $validator,
        ]);

        $controller = new class () {
            public function updateAction(DictResolverMissingTypeCodeFixture $status): void
            {
            }
        };
        $parameter = (new ReflectionMethod($controller::class, 'updateAction'))->getParameters()[0];

        $this->expectException(DictTypeCodeRequiredException::class);
        $this->expectExceptionMessage('Unable to derive dict type');

        $resolver->resolve($parameter, $parameter->getType()?->getName() ?? '');
    }

    public function testResolveThrowsWhenParameterTypeIsNotDictSubclass(): void
    {
        $dictReader = $this->createMock(DictReaderInterface::class);
        $dictReader->expects($this->never())->method('getType');

        $scalarResolver = $this->createMock(ScalarResolverInterface::class);
        $scalarResolver->expects($this->never())->method('resolve');

        /** @var DictResolver $resolver */
        $resolver = $this->make(DictResolver::class, [
            'dictReader' => $dictReader,
            'scalarResolver' => $scalarResolver,
            'validator' => $this->createStub(ValidatorInterface::class),
        ]);

        $parameter = (new ReflectionMethod(OrderController::class, 'updateAction'))->getParameters()[0];

        $this->expectException(UnsupportedDictParameterTypeException::class);
        $this->expectExceptionMessage('DictResolver only supports');

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

        $scalarResolver = $this->createMock(ScalarResolverInterface::class);
        $scalarResolver->expects($this->never())->method('resolve');

        /** @var DictResolver $resolver */
        $resolver = $this->make(DictResolver::class, [
            'dictReader' => $dictReader,
            'scalarResolver' => $scalarResolver,
            'validator' => $this->createStub(ValidatorInterface::class),
        ]);

        $parameter = (new ReflectionMethod(OrderController::class, 'updateAction'))->getParameters()[0];

        $this->expectException(DictTypeNotFoundException::class);
        $this->expectExceptionMessage('order.status');

        $resolver->resolve($parameter, $parameter->getType()?->getName() ?? '');
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

        $scalarResolver = $this->createMock(ScalarResolverInterface::class);
        $scalarResolver->expects($this->once())->method('resolve')->willReturn('missing');

        $validation = $this->createMock(\Switon\Validating\Validation::class);
        $validation->expects($this->once())->method('validate')->willReturn(true);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->once())->method('beginValidate')->willReturn($validation);
        $validator->expects($this->once())->method('endValidate');

        /** @var DictResolver $resolver */
        $resolver = $this->make(DictResolver::class, [
            'dictReader' => $dictReader,
            'scalarResolver' => $scalarResolver,
            'validator' => $validator,
        ]);

        $parameter = (new ReflectionMethod(OrderController::class, 'updateAction'))->getParameters()[0];

        $this->expectException(DictItemNotFoundException::class);
        $this->expectExceptionMessage('did not resolve');

        $resolver->resolve($parameter, $parameter->getType()?->getName() ?? '');
    }
}

final class DictResolverMissingTypeCodeFixture extends Dict
{
    public static function typeCode(): ?string
    {
        return '';
    }
}
