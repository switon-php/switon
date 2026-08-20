<?php

declare(strict_types=1);

namespace Switon\McpClient\Command;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\McpClient\ClientsInterface;

class McpClientCommand
{
    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected ClientsInterface $clients;

    /** Print client id and brief. Does not connect. */
    public function listAction(): int
    {
        $rows = [];
        foreach ($this->clients->all() as $id => $client) {
            $rows[] = [$id, $client->brief()];
        }
        if ($rows === []) {
            $this->console->writeLn('No MCP servers.');

            return 0;
        }

        $this->console->table(['id', 'brief'], $rows);

        return 0;
    }
}
