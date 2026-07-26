<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use Switon\OpenApi\Tests\TestCase;
use Switon\OpenApi\Tests\Unit\Fixtures\PhpReturnSchemaChildFixture;
use Switon\OpenApi\Tests\Unit\Fixtures\PhpReturnSchemaRootFixture;

class PhpReturnSchemaCoverageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/Fixtures/PhpReturnSchemaFixtures.php';
    }

    public function testInfersBuiltinReturnTypes(): void
    {
        $this->assertSame(['type' => 'integer'], $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'intAction'));
        $this->assertSame(['type' => 'number'], $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'floatAction'));
        $this->assertSame(['type' => 'boolean'], $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'boolAction'));
        $this->assertNull($this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'nullAction'));
        $this->assertSame(['type' => 'array'], $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'arrayAction'));
        $this->assertSame(['type' => 'array'], $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'iterableAction'));
        $this->assertSame(['type' => 'object'], $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'objectAction'));
        $this->assertSame(['type' => 'string'], $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'stringAction'));
        $this->assertSame(['type' => 'boolean'], $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'trueAction'));
        $this->assertNull($this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'falseAction'));
        $this->assertNull($this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'mixedAction'));
        $this->assertNull($this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'voidAction'));
        $this->assertNull($this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'neverAction'));
    }

    public function testInfersNamedReturnTypesAndSkipsNonSchemaableTypes(): void
    {
        $this->assertSame(['type' => 'string', 'format' => 'date-time'], $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'dateTimeAction'));
        $this->assertSame(['type' => 'string', 'enum' => ['Alpha', 'Beta']], $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'enumAction'));
        $this->assertSame(['type' => 'integer'], $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'backedIntEnumAction'));
        $this->assertSame(['type' => 'string'], $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'backedStringEnumAction'));
        $this->assertNull($this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'interfaceAction'));
        $this->assertNull($this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'throwableAction'));
        $this->assertNull($this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'generatorAction'));
        $this->assertSame(['type' => 'object'], $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'unknownClassAction'));
        $this->assertNull($this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'intersectionAction'));
    }

    public function testUnionOfOnlyNullishTypesReturnsNull(): void
    {
        $this->assertNull(
            $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'nullishUnionAction'),
        );
    }

    public function testObjectSchemaIncludesUntypedPropertyAndSkipsStaticProperty(): void
    {
        $schema = $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'propertyShapeAction');
        $this->assertSame('object', $schema['type'] ?? null);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('untyped', $schema['properties']);
        $this->assertSame([], $schema['properties']['untyped']);
        $this->assertSame(['type' => 'string'], $schema['properties']['label'] ?? null);
        $this->assertArrayNotHasKey('ignored', $schema['properties']);
    }

    public function testInfersObjectSchemasForSelfStaticParentCircularAndDepthLimits(): void
    {
        $selfSchema = $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'selfAction');
        $this->assertSame('object', $selfSchema['type'] ?? null);
        $this->assertSame(['type' => 'integer'], $selfSchema['properties']['id'] ?? null);

        $staticSchema = $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'staticAction');
        $this->assertSame('object', $staticSchema['type'] ?? null);

        $parentSchema = $this->inferReturnSchema(PhpReturnSchemaChildFixture::class, 'parentAction');
        $this->assertSame('object', $parentSchema['type'] ?? null);
        $this->assertSame(['type' => 'integer'], $parentSchema['properties']['id'] ?? null);

        $recursiveSchema = $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'recursiveAction');
        $this->assertSame('object', $recursiveSchema['type'] ?? null);
        $this->assertSame('circular reference', $recursiveSchema['properties']['next']['description'] ?? null);

        $depthSchema = $this->inferReturnSchema(PhpReturnSchemaRootFixture::class, 'depthAction');
        $this->assertSame('object', $depthSchema['type'] ?? null);
        $this->assertSame('max expansion depth', $depthSchema['properties']['next']['properties']['next']['properties']['next']['properties']['next']['properties']['next']['description'] ?? null);
    }

}
