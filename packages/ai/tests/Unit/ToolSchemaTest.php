<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Tests\TestCase;
use Switon\Core\Json;
use Switon\Ai\ToolSchema;
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

    public function testOfKeepsFullMcpToolBag(): void
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
        $this->assertSame('Create an order.', $tool->definition['description']);
        $this->assertSame(['customer' => ['$ref' => '#/$defs/Customer']], $tool->definition['inputSchema']['properties']);
        $this->assertSame(['customer'], $tool->definition['inputSchema']['required']);
        $this->assertArrayHasKey('$defs', $tool->jsonSchema());
        $this->assertSame($wire['inputSchema']['$defs'], $tool->jsonSchema()['$defs']);
        $this->assertSame($wire['outputSchema'], $tool->definition['outputSchema']);
    }

    public function testOfDefaultsMissingInputSchemaKeys(): void
    {
        $tool = ToolSchema::of(['description' => 'Empty']);

        $this->assertSame('Empty', $tool->definition['description']);
        $this->assertSame([], $tool->definition['inputSchema']['properties'] ?? []);
        $this->assertSame([], $tool->definition['inputSchema']['required'] ?? []);
    }

    public function testConstructorSugarBuildsMcpShapedBag(): void
    {
        $tool = new ToolSchema(
            description: 'Ping',
            properties: ['n' => ['type' => 'integer']],
            required: ['n'],
        );

        $this->assertSame('Ping', $tool->definition['description']);
        $this->assertSame('object', $tool->definition['inputSchema']['type']);
        $this->assertSame(['n' => ['type' => 'integer']], $tool->definition['inputSchema']['properties']);
        $this->assertSame(['n'], $tool->definition['inputSchema']['required']);
    }

    public function testJsonSchemaEmitsAdditionalPropertiesWhenDisabled(): void
    {
        $open = new ToolSchema(description: 'Open.');
        $this->assertArrayNotHasKey('additionalProperties', $open->jsonSchema());

        $closed = new ToolSchema(description: 'Closed.', additionalProperties: false);
        $this->assertFalse($closed->jsonSchema()['additionalProperties']);

        $fromOf = ToolSchema::of([
            'description' => 'Bag',
            'inputSchema' => ['additionalProperties' => false],
        ]);
        $this->assertFalse($fromOf->definition['inputSchema']['additionalProperties']);
    }

    public function testCloneReplacingInputSchemaIsolatesBag(): void
    {
        $tool = ToolSchema::of([
            'description' => 'x',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['a' => ['type' => 'string']],
                'required' => ['a'],
            ],
        ]);
        $copy = clone $tool;
        $copy->definition['description'] = 'y';
        $input = $copy->definition['inputSchema'];
        $input['properties']['b'] = ['type' => 'integer'];
        $copy->definition['inputSchema'] = $input;

        $this->assertSame('x', $tool->definition['description']);
        $this->assertArrayNotHasKey('b', $tool->definition['inputSchema']['properties']);
    }

    public function testJsonSchemaDefaultsWhenInputSchemaMissingOrObjectProperties(): void
    {
        $missing = ToolSchema::of(['description' => 'No schema']);
        unset($missing->definition['inputSchema']);
        $fallback = $missing->jsonSchema();
        $this->assertSame('object', $fallback['type']);
        $this->assertInstanceOf(stdClass::class, $fallback['properties']);

        $objectProps = ToolSchema::of([
            'description' => 'Already object',
            'inputSchema' => [
                'properties' => new stdClass(),
            ],
        ]);
        $schema = $objectProps->jsonSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertInstanceOf(stdClass::class, $schema['properties']);
    }
}
