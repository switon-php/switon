<?php

declare(strict_types=1);

namespace Switon\Core\Tests\Unit;

use Attribute;
use ReflectionClass;
use ReflectionMethod;
use Switon\Core\Attributes;
use Switon\Core\Tests\TestCase;

final class AttributesTest extends TestCase
{
    public function testHasFindsExactAndSubclassAttributes(): void
    {
        $exact = new ReflectionClass(AttributesTestExactTarget::class);
        $this->assertTrue(Attributes::has($exact, AttributesTestMarker::class));
        $this->assertFalse(Attributes::has($exact, AttributesTestDerivedMarker::class));

        $derived = new ReflectionClass(AttributesTestDerivedTarget::class);
        $this->assertTrue(Attributes::has($derived, AttributesTestMarker::class));
    }

    public function testFirstReturnsInstanceOrNull(): void
    {
        $rClass = new ReflectionClass(AttributesTestExactTarget::class);

        $attribute = Attributes::first($rClass, AttributesTestMarker::class);
        $this->assertInstanceOf(AttributesTestMarker::class, $attribute);
        $this->assertSame('exact', $attribute->label);

        $this->assertNull(Attributes::first($rClass, AttributesTestDerivedMarker::class));
    }

    public function testFirstFindsSubclassAttribute(): void
    {
        $rClass = new ReflectionClass(AttributesTestDerivedTarget::class);

        $attribute = Attributes::first($rClass, AttributesTestMarker::class);
        $this->assertInstanceOf(AttributesTestDerivedMarker::class, $attribute);
        $this->assertSame('derived', $attribute->label);
    }

    public function testAllReturnsEveryMatchingInstance(): void
    {
        $rMethod = new ReflectionMethod(AttributesTestMultiTarget::class, 'run');

        $attributes = Attributes::all($rMethod, AttributesTestRepeatableMarker::class);
        $this->assertCount(2, $attributes);
        $this->assertSame(
            ['first', 'second'],
            array_map(static fn (AttributesTestRepeatableMarker $marker): string => $marker->label, $attributes),
        );
    }

    public function testAllReturnsEmptyListWhenMissing(): void
    {
        $rClass = new ReflectionClass(AttributesTestExactTarget::class);

        $this->assertSame([], Attributes::all($rClass, AttributesTestDerivedMarker::class));
    }

    public function testWorksOnPropertyAndParameter(): void
    {
        $rProperty = (new ReflectionClass(AttributesTestPropertyTarget::class))->getProperty('value');
        $rParameter = (new ReflectionMethod(AttributesTestPropertyTarget::class, 'run'))->getParameters()[0];

        $this->assertTrue(Attributes::has($rProperty, AttributesTestMarker::class));
        $this->assertSame('property', Attributes::first($rProperty, AttributesTestMarker::class)?->label);

        $this->assertTrue(Attributes::has($rParameter, AttributesTestMarker::class));
        $this->assertSame('parameter', Attributes::first($rParameter, AttributesTestMarker::class)?->label);
    }
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class AttributesTestMarker
{
    public function __construct(public string $label = 'marker')
    {
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
class AttributesTestDerivedMarker extends AttributesTestMarker
{
}

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class AttributesTestRepeatableMarker
{
    public function __construct(public string $label = 'marker')
    {
    }
}

#[AttributesTestMarker('exact')]
class AttributesTestExactTarget
{
}

#[AttributesTestDerivedMarker('derived')]
class AttributesTestDerivedTarget
{
}

class AttributesTestMultiTarget
{
    #[AttributesTestRepeatableMarker('first')]
    #[AttributesTestRepeatableMarker('second')]
    public function run(): void
    {
    }
}

class AttributesTestPropertyTarget
{
    #[AttributesTestMarker('property')]
    public string $value = '';

    public function run(#[AttributesTestMarker('parameter')] string $input): void
    {
    }
}
