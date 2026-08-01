<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Mcp\Command\McpCommand;
use Switon\Mcp\StdioServerInterface;
use Switon\Mcp\Tests\TestCase;

#[CoversClass(McpCommand::class)]
class McpCommandTest extends TestCase
{
    public function testServeActionRunsStdioServer(): void
    {
        $stdioServer = $this->createMock(StdioServerInterface::class);
        $stdioServer->expects($this->once())->method('serve');

        $command = $this->make(McpCommand::class, [
            'stdioServer' => $stdioServer,
        ]);

        $this->assertSame(0, $command->serveAction());
    }
}
