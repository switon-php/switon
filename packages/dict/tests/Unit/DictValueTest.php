<?php

declare(strict_types=1);

namespace Switon\Dict\Tests\Unit;

use ReflectionClass;
use Switon\Dict\Attribute\DictValue;
use Switon\Dict\Dict;
use Switon\Dict\DictEntry;
use Switon\Dict\DictReaderInterface;
use Switon\Dict\Exception\DictTypeCodeRequiredException;
use Switon\Dict\Tests\Fixtures\OrderStatus;
use Switon\Dict\Tests\TestCase;
use Switon\Validating\Validation;
use Switon\Validating\ValidatorInterface;
use Error;

class DictValueTest extends TestCase
{
    public function testValidateUsesDictReaderInterface(): void
    {
        $dictReader = $this->createMock(DictReaderInterface::class);
        $dictReader->expects($this->once())
            ->method('getItem')
            ->with('order.status', 'paid', true)
            ->willReturn(new DictEntry('order.status', 'paid', 'Paid', 'paid'));

        $this->container->set(DictReaderInterface::class, $dictReader);

        /** @var DictValue $attribute */
        $attribute = $this->container->make(DictValue::class, [
            'type' => OrderStatus::class,
        ]);

        /** @var ValidatorInterface $validator */
        $validator = $this->container->get(ValidatorInterface::class);
        $validation = new Validation($validator, []);
        $validation->field = 'status';
        $validation->value = 'paid';

        $this->assertTrue($attribute->validate($validation));
    }

    public function testNormalizeInputReturnsResolvedItemValue(): void
    {
        $dictReader = $this->createMock(DictReaderInterface::class);
        $dictReader->expects($this->once())
            ->method('getItem')
            ->with('order.status', 'paid', true)
            ->willReturn(new DictEntry('order.status', 'paid', 'Paid', '1'));

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->never())->method('beginValidate');
        $validator->expects($this->never())->method('endValidate');
        $this->container->set(DictReaderInterface::class, $dictReader);
        $this->container->set(ValidatorInterface::class, $validator);

        /** @var DictValue $attribute */
        $attribute = $this->container->make(DictValue::class, [
            'type' => OrderStatus::class,
        ]);

        $property = (new ReflectionClass(DictValueEntityFixture::class))->getProperty('status');

        $this->assertSame('1', $attribute->normalizeInput($property, 'paid'));
    }

    public function testNormalizeInputUsesValidatorWhenItemIsMissing(): void
    {
        $dictReader = $this->createMock(DictReaderInterface::class);
        $dictReader->expects($this->once())
            ->method('getItem')
            ->with('order.status', 'missing', true)
            ->willReturn(null);
        $this->container->set(DictReaderInterface::class, $dictReader);

        $validation = new Validation($this->createStub(ValidatorInterface::class), []);
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->once())
            ->method('beginValidate')
            ->with([])
            ->willReturn($validation);
        $validator->expects($this->never())
            ->method('endValidate');
        $this->container->set(DictReaderInterface::class, $dictReader);
        $this->container->set(ValidatorInterface::class, $validator);

        /** @var DictValue $attribute */
        $attribute = $this->container->make(DictValue::class, [
            'type' => OrderStatus::class,
        ]);

        $property = (new ReflectionClass(DictValueEntityFixture::class))->getProperty('status');
        $this->expectException(Error::class);
        $this->expectExceptionMessage('must not be accessed before initialization');

        $attribute->normalizeInput($property, 'missing');
    }

    public function testNormalizeInputReturnsNullForEmptyNullableProperty(): void
    {
        $dictReader = $this->createMock(DictReaderInterface::class);
        $dictReader->expects($this->never())->method('getItem');

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->never())->method('beginValidate');
        $validator->expects($this->never())->method('endValidate');
        $this->container->set(DictReaderInterface::class, $dictReader);
        $this->container->set(ValidatorInterface::class, $validator);

        /** @var DictValue $attribute */
        $attribute = $this->container->make(DictValue::class, [
            'type' => OrderStatus::class,
        ]);

        $property = (new ReflectionClass(DictValueEntityFixture::class))->getProperty('nullableStatus');

        $this->assertNull($attribute->normalizeInput($property, ''));
    }

    public function testValidateRaisesWhenTypeOwnedCodeIsMissing(): void
    {
        $dictReader = $this->createMock(DictReaderInterface::class);
        $dictReader->expects($this->never())->method('getItem');

        $validation = new Validation($this->createStub(ValidatorInterface::class), []);
        $validation->field = 'status';
        $validation->sourceClass = DictValueBrokenFixture::class;
        $validation->value = 'paid';

        $attribute = new DictValue(BrokenOrderStatus::class);

        $this->expectException(DictTypeCodeRequiredException::class);

        $attribute->validate($validation);
    }
}

class DictValueEntityFixture
{
    #[DictValue(OrderStatus::class)]
    public int $status;

    #[DictValue(OrderStatus::class)]
    public ?int $nullableStatus = null;
}

final class BrokenOrderStatus extends Dict
{
    public static function typeCode(): ?string
    {
        return null;
    }
}

class DictValueBrokenFixture
{
    #[DictValue(BrokenOrderStatus::class)]
    public int $status;
}
