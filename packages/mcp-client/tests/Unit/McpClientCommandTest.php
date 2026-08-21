<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\ConsoleInterface;
use Switon\McpClient\ClientInterface;
use Switon\McpClient\ClientsInterface;
use Switon\McpClient\Command\McpClientCommand;
use Switon\McpClient\Tests\TestCase;

#[CoversClass(McpClientCommand::class)]
class McpClientCommandTest extends TestCase
{
    public function testListPrintsIdAndBrief(): void
    {
        $app = $this->createStub(ClientInterface::class);
        $app->method('brief')->willReturn('php noop.php');
        $docs = $this->createStub(ClientInterface::class);
        $docs->method('brief')->willReturn('https://example.com/mcp');

        $clients = $this->createStub(ClientsInterface::class);
        $clients->method('all')->willReturn([
            'app' => $app,
            'docs' => $docs,
        ]);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('table')->with(
            ['id', 'brief'],
            [
                ['app', 'php noop.php'],
                ['docs', 'https://example.com/mcp'],
            ],
        );
        $console->expects($this->never())->method('writeLn');

        $this->assertSame(0, $this->command($clients, $console)->listAction());
    }

    public function testEmptyListSaysNone(): void
    {
        $clients = $this->createStub(ClientsInterface::class);
        $clients->method('all')->willReturn([]);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with('No MCP servers.');
        $console->expects($this->never())->method('table');

        $this->assertSame(0, $this->command($clients, $console)->listAction());
    }

    protected function command(ClientsInterface $clients, ConsoleInterface $console): McpClientCommand
    {
        return $this->make(McpClientCommand::class, [
            'clients' => $clients,
            'console' => $console,
        ]);
    }
}
