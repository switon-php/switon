<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\McpClient\Exception;
use Switon\McpClient\Tests\Fixtures\FixedClient;
use Switon\McpClient\Tests\Fixtures\MapClients;
use Switon\McpClient\Tests\Fixtures\StubToolAgent;
use Switon\McpClient\Tests\TestCase;
use Switon\McpClient\Tool\McpToolsTool;

#[CoversClass(McpToolsTool::class)]
class McpToolsToolTest extends TestCase
{
    public function testCallListsFlatArgumentNames(): void
    {
        $rows = $this->list([
            'plain' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'mode' => ['enum' => ['a', 'b']],
                    'note' => ['type' => ['string', 'null']],
                ],
            ],
        ]);

        $this->assertSame(['mode', 'note'], $rows[0]['params']);
        $tool = $this->make(McpToolsTool::class, ['clients' => new MapClients([])]);
        $this->assertTrue($tool->isReadonly('mcp_tools', []));
        $this->assertSame(['server'], $tool->schema('mcp_tools')->definition['inputSchema']['required']);
    }

    public function testCallDoesNotFlattenAComplexSchema(): void
    {
        $complex = 'Params structure is complex. Read with mcp_schema.';
        $rows = $this->list([
            'choice' => ['type' => 'object', 'oneOf' => [['type' => 'string']]],
            'linked' => ['type' => 'object', 'properties' => ['page' => ['$ref' => '#/x']]],
            'open' => ['type' => 'object', 'additionalProperties' => true, 'properties' => []],
            'nested' => ['type' => 'object', 'properties' => ['rows' => ['type' => 'array', 'items' => ['type' => 'object']]]],
            'bag' => ['type' => 'object', 'properties' => ['value' => ['enum' => [['a' => 1]]]]],
            'mixed' => ['type' => 'object', 'properties' => ['value' => ['type' => ['string', 'object']]]],
            'openFlag' => ['type' => 'object', 'properties' => ['flag' => ['type' => 'string', 'additionalProperties' => true]]],
            'badEnum' => ['type' => 'object', 'properties' => ['value' => ['enum' => 'no']]],
            'badType' => ['type' => 'object', 'properties' => ['value' => ['type' => 1]]],
        ]);

        foreach ($rows as $row) {
            $this->assertSame($complex, $row['params'], $row['name']);
        }
    }

    public function testCallSkipsAnEntryThatIsNotAToolSchema(): void
    {
        $client = new FixedClient();
        $client->tools = [
            'ping' => ToolSchema::of([
                'name' => 'ping',
                'inputSchema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
            ]),
            'junk' => 'no',
        ];
        $tool = $this->make(McpToolsTool::class, ['clients' => new MapClients(['docs' => $client])]);

        $rows = $tool->call(StubToolAgent::in(), new ToolCall(name: 'mcp_tools'), ['server' => 'docs'])['tools'];

        $this->assertSame(['ping'], array_column($rows, 'name'));
    }

    public function testCallRejectsABlankServerId(): void
    {
        $tool = $this->make(McpToolsTool::class, ['clients' => new MapClients([])]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('MCP server id must be a string.');
        $tool->call(StubToolAgent::in(), new ToolCall(name: 'mcp_tools'), ['server' => '']);
    }

    /**
     * @param array<string, array<string, mixed>> $schemas
     *
     * @return list<array{name: string, description: string, params: list<string>|string}>
     */
    protected function list(array $schemas): array
    {
        $tools = [];
        foreach ($schemas as $name => $schema) {
            $tools[$name] = ToolSchema::of([
                'name' => $name,
                'description' => $name,
                'inputSchema' => $schema,
            ]);
        }
        $tool = $this->make(McpToolsTool::class, [
            'clients' => new MapClients(['docs' => new FixedClient(tools: $tools)]),
        ]);
        $listed = $tool->call(StubToolAgent::in(), new ToolCall(name: 'mcp_tools'), ['server' => 'docs']);

        return $listed['tools'];
    }
}
