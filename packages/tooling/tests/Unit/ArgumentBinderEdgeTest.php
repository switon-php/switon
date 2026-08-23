<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\ToolSchema;
use Switon\Tooling\ArgumentBinder;
use Switon\Tooling\Exception\ArgumentBindException;
use Switon\Tooling\Tests\ToolTestCase;

#[CoversClass(ArgumentBinder::class)]
class ArgumentBinderEdgeTest extends ToolTestCase
{
    public function testBindReturnsArgumentsWhenTheSchemaIsNotAnObject(): void
    {
        $schema = ToolSchema::of(['inputSchema' => 'text']);

        $this->assertSame(['a' => 1], (new ArgumentBinder())->bind($schema, ['a' => 1]));
    }

    public function testBindSkipsANullListItem(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'items' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ],
        ]);

        $this->assertSame(
            ['items' => [null, 'a']],
            (new ArgumentBinder())->bind($schema, ['items' => [null, 'a']]),
        );
    }

    public function testBindRequiresAFieldThatHasNoPropertySchema(): void
    {
        $schema = ToolSchema::of([
            'inputSchema' => [
                'type' => 'object',
                'required' => ['name'],
            ],
        ]);

        $this->expectException(ArgumentBindException::class);
        $this->expectExceptionMessage('name required');

        (new ArgumentBinder())->bind($schema, []);
    }

    public function testBindLeavesExtraKeysWhenPatternPropertiesAreDeclared(): void
    {
        $schema = ToolSchema::of([
            'inputSchema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'patternProperties' => ['^x' => ['type' => 'string']],
                'properties' => [
                    'a' => ['type' => 'string'],
                ],
            ],
        ]);

        $this->assertSame(
            ['a' => '1', 'extra' => '2'],
            (new ArgumentBinder())->bind($schema, ['a' => '1', 'extra' => '2']),
        );
    }

    public function testBindAcceptsNullWhenTheConstIsNull(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'v' => ['const' => null],
        ], required: ['v']);

        $this->assertSame(['v' => null], (new ArgumentBinder())->bind($schema, ['v' => null]));
    }

    public function testBindAcceptsNullWhenTheEnumContainsNull(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'v' => ['enum' => [null, 'a']],
        ], required: ['v']);

        $this->assertSame(['v' => null], (new ArgumentBinder())->bind($schema, ['v' => null]));
    }

    public function testBindAcceptsNullWhenABranchOmitsItsType(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'v' => ['oneOf' => [['enum' => [null]]]],
        ], required: ['v']);

        $this->assertSame(['v' => null], (new ArgumentBinder())->bind($schema, ['v' => null]));
    }

    public function testBindAcceptsAValueWhenTheTypeIsNotAList(): void
    {
        $schema = ToolSchema::of([
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'v' => ['type' => ['string' => 'widget']],
                ],
            ],
        ]);

        $this->assertSame(['v' => true], (new ArgumentBinder())->bind($schema, ['v' => true]));
    }

    public function testBindAcceptsAnExplicitNullType(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'v' => ['type' => 'null'],
        ], required: ['v']);

        $this->assertSame(['v' => null], (new ArgumentBinder())->bind($schema, ['v' => null]));
    }

    public function testBindCoercesDigitStringToInteger(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'limit' => ['type' => 'integer'],
        ]);

        $this->assertSame(['limit' => 20], (new ArgumentBinder())->bind($schema, ['limit' => '20']));
        $this->assertSame(['limit' => -3], (new ArgumentBinder())->bind($schema, ['limit' => '-3']));
    }

    public function testBindDoesNotCoerceIntegerToString(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'id' => ['type' => 'string'],
        ]);

        $this->expectException(ArgumentBindException::class);
        $this->expectExceptionMessage('id type invalid');

        (new ArgumentBinder())->bind($schema, ['id' => 42]);
    }

    public function testBindSkipsCoerceWhenStringAndIntegerAreBothAllowed(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'v' => ['type' => ['string', 'integer']],
        ]);

        $this->assertSame(['v' => '20'], (new ArgumentBinder())->bind($schema, ['v' => '20']));
        $this->assertSame(['v' => 20], (new ArgumentBinder())->bind($schema, ['v' => 20]));
    }

    public function testBindCoercesDigitStringWhenIntegerOrNull(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'limit' => ['type' => ['integer', 'null']],
        ]);

        $this->assertSame(['limit' => 7], (new ArgumentBinder())->bind($schema, ['limit' => '7']));
    }

    public function testBindLeavesNonDigitStringForIntegerTypeCheck(): void
    {
        $schema = new ToolSchema(description: 'd', properties: [
            'limit' => ['type' => 'integer'],
        ]);

        $this->expectException(ArgumentBindException::class);
        $this->expectExceptionMessage('limit type invalid');

        (new ArgumentBinder())->bind($schema, ['limit' => '1.5']);
    }
}
