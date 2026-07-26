<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use Switon\OpenApi\PhpReturnSchema;
use Switon\OpenApi\Tests\TestCase;
use Switon\OpenApi\Tests\Unit\Fixtures\ReturnTypeSamples;

class PhpReturnSchemaTest extends TestCase
{
    public function testInfersObjectFromPublicProperties(): void
    {
        $schema = $this->inferReturnSchema(ReturnTypeSamples::class, 'editAction');
        $this->assertIsArray($schema);
        $this->assertSame('object', $schema['type'] ?? null);
        $this->assertIsArray($schema['properties'] ?? null);
        $this->assertSame(['type' => 'integer'], $schema['properties']['id'] ?? null);
        $this->assertSame(['type' => 'string'], $schema['properties']['name'] ?? null);
    }

    public function testVoidReturnsNull(): void
    {
        $this->assertNull($this->inferReturnSchema(ReturnTypeSamples::class, 'voidAction'));
    }

    public function testHttpResponseInterfaceReturnsNull(): void
    {
        $this->assertNull($this->inferReturnSchema(ReturnTypeSamples::class, 'responseAction'));
    }

    public function testNullableUnionUsesObjectSchema(): void
    {
        $schema = $this->inferReturnSchema(ReturnTypeSamples::class, 'nullableAction');
        $this->assertIsArray($schema);
        $this->assertSame('object', $schema['type'] ?? null);
    }

    public function testBackedEnumReturnsScalarType(): void
    {
        $schema = $this->inferReturnSchema(ReturnTypeSamples::class, 'backedEnumAction');
        $this->assertSame(['type' => 'integer'], $schema);
    }

    public function testIntersectionReturnTypeReturnsNull(): void
    {
        $this->assertNull($this->inferReturnSchema(ReturnTypeSamples::class, 'intersectAction'));
    }

    public function testIntScalarReturn(): void
    {
        $this->assertSame(['type' => 'integer'], $this->inferReturnSchema(ReturnTypeSamples::class, 'intScalarAction'));
    }

    public function testArrayBuiltinReturn(): void
    {
        $this->assertSame(['type' => 'array'], $this->inferReturnSchema(ReturnTypeSamples::class, 'arrayBuiltinAction'));
    }

    public function testSelfReturnUsesDeclaringClass(): void
    {
        $schema = $this->inferReturnSchema(ReturnTypeSamples::class, 'selfAction');
        $this->assertIsArray($schema);
        $this->assertSame('object', $schema['type'] ?? null);
    }

    public function testIntOrStringUnionUsesFirstConcreteSchema(): void
    {
        $schema = $this->inferReturnSchema(ReturnTypeSamples::class, 'intOrStringAction');
        $this->assertIsArray($schema);
        $this->assertContains($schema['type'] ?? null, ['integer', 'string']);
    }

    public function testUnitEnumReturnListsCases(): void
    {
        $schema = $this->inferReturnSchema(ReturnTypeSamples::class, 'unitEnumAction');
        $this->assertIsArray($schema);
        $this->assertSame('string', $schema['type'] ?? null);
        $this->assertSame(['Alpha', 'Beta'], $schema['enum'] ?? null);
    }
}
