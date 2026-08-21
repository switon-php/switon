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
use Switon\McpClient\Tool\McpSchemaTool;

#[CoversClass(McpSchemaTool::class)]
class McpSchemaToolTest extends TestCase
{
    public function testCallReturnsTheArgumentSchema(): void
    {
        $input = ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']];
        $tool = $this->tool(ToolSchema::of([
            'name' => 'search',
            'description' => 'Search',
            'inputSchema' => $input,
        ]));

        $result = $tool->call(StubToolAgent::in(), new ToolCall(name: 'mcp_schema'), [
            'server' => 'docs',
            'tool' => 'search',
        ]);

        $this->assertSame('search', $result['name']);
        $this->assertSame('Search', $result['description']);
        $this->assertSame($input, $result['arguments']);
        $this->assertTrue($tool->isReadonly('mcp_schema', []));
        $this->assertSame(['server', 'tool'], $tool->schema('mcp_schema')->definition['inputSchema']['required']);
    }

    public function testCallRejectsAnUnknownTool(): void
    {
        $tool = $this->tool(ToolSchema::of(['name' => 'search', 'description' => 'Search']));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown MCP tool "missing" on "docs".');
        $tool->call(StubToolAgent::in(), new ToolCall(name: 'mcp_schema'), [
            'server' => 'docs',
            'tool' => 'missing',
        ]);
    }

    public function testCallRejectsABlankServerId(): void
    {
        $tool = $this->tool(ToolSchema::of(['name' => 'search']));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('MCP server id must be a string.');
        $tool->call(StubToolAgent::in(), new ToolCall(name: 'mcp_schema'), ['server' => '', 'tool' => 'search']);
    }

    public function testCallRejectsABlankToolName(): void
    {
        $tool = $this->tool(ToolSchema::of(['name' => 'search']));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('MCP tool name must be a string.');
        $tool->call(StubToolAgent::in(), new ToolCall(name: 'mcp_schema'), ['server' => 'docs', 'tool' => '']);
    }

    public function testCallReplacesANonArrayInputSchema(): void
    {
        $tool = $this->tool(ToolSchema::of([
            'name' => 'search',
            'inputSchema' => 'broken',
        ]));

        $result = $tool->call(StubToolAgent::in(), new ToolCall(name: 'mcp_schema'), [
            'server' => 'docs',
            'tool' => 'search',
        ]);

        $this->assertSame(['type' => 'object', 'properties' => []], $result['arguments']);
    }

    protected function tool(ToolSchema $schema): McpSchemaTool
    {
        return $this->make(McpSchemaTool::class, [
            'clients' => new MapClients([
                'docs' => new FixedClient(tools: ['search' => $schema]),
            ]),
        ]);
    }
}
