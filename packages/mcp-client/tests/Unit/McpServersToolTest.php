<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\ToolCall;
use Switon\McpClient\Tests\Fixtures\FixedClient;
use Switon\McpClient\Tests\Fixtures\MapClients;
use Switon\McpClient\Tests\Fixtures\StubToolAgent;
use Switon\McpClient\Tests\TestCase;
use Switon\McpClient\Tool\McpServersTool;

#[CoversClass(McpServersTool::class)]
class McpServersToolTest extends TestCase
{
    public function testCallListsIdAndBriefWithoutConnecting(): void
    {
        $showdoc = new FixedClient('https://example.test/mcp');
        $playwright = new FixedClient('playwright-mcp --headless');
        $tool = $this->make(McpServersTool::class, [
            'clients' => new MapClients([
                'showdoc' => $showdoc,
                'playwright' => $playwright,
            ]),
        ]);

        $result = $tool->call(StubToolAgent::in(), new ToolCall(name: 'mcp_servers'), []);

        $this->assertSame([
            ['id' => 'showdoc', 'brief' => 'https://example.test/mcp'],
            ['id' => 'playwright', 'brief' => 'playwright-mcp --headless'],
        ], $result['servers']);
        $this->assertSame([], $showdoc->refreshes);
        $this->assertSame([], $playwright->refreshes);
        $this->assertTrue($tool->isReadonly('mcp_servers', []));
        $this->assertSame([], $tool->schema('mcp_servers')->definition['inputSchema']['properties']);
    }
}
