<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Json;
use Switon\Mcp\Tests\TestCase;
use Switon\Mcp\ToolSchema;
use stdClass;

#[CoversClass(ToolSchema::class)]
class ToolSchemaTest extends TestCase
{
    public function testJsonSchemaIncludesPropertiesAndRequired(): void
    {
        $tool = new ToolSchema(
            description: 'Weather lookup.',
            properties: ['location' => ['type' => 'string']],
            required: ['location'],
        );

        $this->assertSame([
            'type' => 'object',
            'properties' => ['location' => ['type' => 'string']],
            'required' => ['location'],
        ], $tool->jsonSchema());
    }

    public function testJsonSchemaEncodesEmptyPropertiesAsObject(): void
    {
        $tool = new ToolSchema(description: 'List sessions.');

        $schema = $tool->jsonSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertInstanceOf(stdClass::class, $schema['properties']);
        $this->assertArrayNotHasKey('required', $schema);

        $decoded = Json::parse(Json::stringify(['parameters' => $schema]));
        $this->assertSame([], $decoded['parameters']['properties']);
    }

    public function testOfKeepsFullCatalogBag(): void
    {
        $wire = [
            'name' => 'create_order',
            'description' => 'Create an order.',
            'annotations' => ['readOnlyHint' => false],
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'customer' => ['$ref' => '#/$defs/Customer'],
                ],
                'required' => ['customer'],
                '$defs' => [
                    'Customer' => [
                        'type' => 'object',
                        'properties' => ['id' => ['type' => 'string']],
                        'required' => ['id'],
                    ],
                ],
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => ['orderId' => ['type' => 'string']],
            ],
        ];

        $tool = ToolSchema::of($wire);

        $this->assertSame($wire, $tool->definition);
        $this->assertArrayHasKey('$defs', $tool->jsonSchema());
        $this->assertSame($wire['outputSchema'], $tool->definition['outputSchema']);
    }

    public function testJsonSchemaFillsMissingInputShape(): void
    {
        $missing = ToolSchema::of(['description' => 'x']);
        $fallback = $missing->jsonSchema();
        $this->assertSame('object', $fallback['type']);
        $this->assertInstanceOf(stdClass::class, $fallback['properties']);

        $untyped = ToolSchema::of([
            'inputSchema' => ['properties' => ['q' => ['type' => 'string']]],
        ]);
        $this->assertSame('object', $untyped->jsonSchema()['type']);
        $this->assertSame(['q' => ['type' => 'string']], $untyped->jsonSchema()['properties']);

        $ready = ToolSchema::of([
            'inputSchema' => [
                'type' => 'object',
                'properties' => new stdClass(),
            ],
        ]);
        $this->assertInstanceOf(stdClass::class, $ready->jsonSchema()['properties']);
    }

    public function testJsonSchemaOmitsAdditionalPropertiesUnlessClosed(): void
    {
        $open = new ToolSchema(description: 'Open.');
        $this->assertArrayNotHasKey('additionalProperties', $open->jsonSchema());

        $closed = new ToolSchema(description: 'Closed.', additionalProperties: false);
        $this->assertFalse($closed->jsonSchema()['additionalProperties']);
    }

    public function testHasNoHandlerProperty(): void
    {
        $tool = new ToolSchema(description: 'x');
        $this->assertObjectNotHasProperty('handler', $tool);
    }
}
