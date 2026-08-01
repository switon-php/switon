<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Mcp\ArgumentBinder;
use Switon\Mcp\Exception\InvalidParamsException;
use Switon\Mcp\Tests\TestCase;
use Switon\Mcp\ToolSchema;

#[CoversClass(ArgumentBinder::class)]
class ArgumentBinderTest extends TestCase
{
    public function testBindFillsDefaultsThenChecksRequired(): void
    {
        $schema = new ToolSchema(
            description: 'x',
            properties: [
                'book' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'default' => 20],
                'rev2' => ['type' => 'string', 'default' => 'HEAD'],
            ],
            required: ['book'],
        );

        $out = $this->binder()->bind($schema, ['book' => 'demo']);

        $this->assertSame([
            'book' => 'demo',
            'limit' => 20,
            'rev2' => 'HEAD',
        ], $out);
    }

    public function testBindDoesNotOverwritePresentKeys(): void
    {
        $schema = new ToolSchema(
            description: 'x',
            properties: ['limit' => ['type' => 'integer', 'default' => 20]],
        );

        $out = $this->binder()->bind($schema, ['limit' => 5]);

        $this->assertSame(['limit' => 5], $out);
    }

    public function testBindRejectsMissingRequired(): void
    {
        $schema = new ToolSchema(
            description: 'x',
            properties: ['book' => ['type' => 'string']],
            required: ['book'],
        );

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessage('book is required');

        $this->binder()->bind($schema, []);
    }

    public function testBindCoercesStringAndInteger(): void
    {
        $schema = new ToolSchema(
            description: 'x',
            properties: [
                'book' => ['type' => 'string'],
                'limit' => ['type' => 'integer'],
            ],
            required: ['book', 'limit'],
        );

        $out = $this->binder()->bind($schema, [
            'book' => 42,
            'limit' => '20',
        ]);

        $this->assertSame(['book' => '42', 'limit' => 20], $out);
    }

    public function testBindSkipsNullAndAbsentOptionalValues(): void
    {
        $schema = new ToolSchema(
            description: 'x',
            properties: [
                'book' => ['type' => 'string'],
                'note' => ['type' => 'string'],
            ],
        );

        $out = $this->binder()->bind($schema, ['note' => null, 'extra' => true]);

        $this->assertSame(['note' => null, 'extra' => true], $out);
    }

    public function testBindRejectsBool(): void
    {
        $schema = new ToolSchema(
            description: 'x',
            properties: [
                'book' => ['type' => 'string'],
                'limit' => ['type' => 'integer'],
            ],
            required: ['book', 'limit'],
        );

        foreach ([
            [['book' => false, 'limit' => 1], 'book must be a string'],
            [['book' => true, 'limit' => 1], 'book must be a string'],
            [['book' => 'demo', 'limit' => false], 'limit must be an integer'],
            [['book' => 'demo', 'limit' => true], 'limit must be an integer'],
        ] as [$arguments, $message]) {
            try {
                $this->binder()->bind($schema, $arguments);
                $this->fail('Expected InvalidParamsException');
            } catch (InvalidParamsException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testBindRejectsBadInteger(): void
    {
        $schema = new ToolSchema(
            description: 'x',
            properties: ['limit' => ['type' => 'integer']],
            required: ['limit'],
        );

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessage('limit must be an integer');

        $this->binder()->bind($schema, ['limit' => '1.5']);
    }

    public function testBindIgnoresOtherTypes(): void
    {
        $schema = new ToolSchema(
            description: 'x',
            properties: ['ops' => ['type' => 'array']],
            required: ['ops'],
        );
        $ops = [['op' => 'x']];

        $out = $this->binder()->bind($schema, ['ops' => $ops]);

        $this->assertSame(['ops' => $ops], $out);
    }

    protected function binder(): ArgumentBinder
    {
        return $this->make(ArgumentBinder::class);
    }
}
