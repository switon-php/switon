<?php

declare(strict_types=1);

namespace Switon\Mcp\Command;

use Switon\Command\Attribute\Hidden;
use Switon\Core\Attribute\Autowired;
use Switon\Mcp\StdioServerInterface;

/**
 * CLI: mcp serve — run MCP over stdio.
 *
 * @see \Switon\Mcp\StdioServerInterface
 */
#[Hidden]
class McpCommand
{
    #[Autowired] protected StdioServerInterface $stdioServer;

    /** Runs MCP over stdio until stdin closes. */
    public function serveAction(): int
    {
        $this->stdioServer->serve();

        return 0;
    }
}
