<?php

declare(strict_types=1);

namespace Switon\Dict\Tests\Unit;

use Switon\Dict\Attribute\DictValue;
use Switon\Dict\Dict;
use Switon\Dict\DictEntry;
use Switon\Dict\DictReaderInterface;
use Switon\Dict\Tests\Fixtures\OrderStatus;
use Switon\Dict\Tests\TestCase;
use Switon\Validating\Validation;
use Switon\Validating\ValidatorInterface;
use Error;
use ReflectionProperty;

class DictValueTest extends TestCase
{
    public function testDictStringCastReturnsStoredValue(): void
    {
        $dict = Dict::of('order.status', 'paid', 'Paid', 'paid');

        $this->assertSame('paid', (string) $dict);
    }

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

        $this->assertSame('1', $attribute->normalizeInput(DictValueEntityFixture::statusProperty(), 'paid'));
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

        $this->expectException(Error::class);
        $this->expectExceptionMessage('must not be accessed before initialization');

        $attribute->normalizeInput(DictValueEntityFixture::statusProperty(), 'missing');
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

        $this->assertNull($attribute->normalizeInput(DictValueEntityFixture::nullableStatusProperty(), ''));
    }

    public function testValidateReturnsFalseWhenTypeOwnedCodeIsMissing(): void
    {
        $dictReader = $this->createMock(DictReaderInterface::class);
        $dictReader->expects($this->once())
            ->method('getItem')
            ->with('', 'paid', true)
            ->willReturn(null);

        $validation = new Validation($this->createStub(ValidatorInterface::class), []);
        $validation->field = 'status';
        $validation->sourceClass = DictValueBrokenFixture::class;
        $validation->value = 'paid';

        $this->container->set(DictReaderInterface::class, $dictReader);

        /** @var DictValue $attribute */
        $attribute = $this->container->make(DictValue::class, [
            'type' => BrokenOrderStatus::class,
        ]);

        $this->assertFalse($attribute->validate($validation));
    }
}

class DictValueEntityFixture
{
    #[DictValue(OrderStatus::class)]
    public int $status;

    #[DictValue(OrderStatus::class)]
    public ?int $nullableStatus = null;

    public static function statusProperty(): ReflectionProperty
    {
        return new ReflectionProperty(self::class, 'status');
    }

    public static function nullableStatusProperty(): ReflectionProperty
    {
        return new ReflectionProperty(self::class, 'nullableStatus');
    }
}

class BrokenOrderStatus extends Dict
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
