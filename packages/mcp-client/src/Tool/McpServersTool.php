<?php

declare(strict_types=1);

namespace Switon\McpClient\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolInterface;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Lazy;
use Switon\McpClient\ClientsInterface;

/**
 * Tool `mcp_servers`: MCP clients registered from `mcp.yml`.
 */
class McpServersTool implements ToolInterface
{
    #[Autowired] protected ClientsInterface|Lazy $clients;

    protected ?ToolSchema $schema = null;

    public function schema(string $name): ?ToolSchema
    {
        unset($name);
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'List registered MCP servers (id and brief). Does not connect.',
            properties: [],
            required: [],
        );
    }

    /** @return array{servers: list<array{id: string, brief: string}>} */
    public function call(AgentInterface $agent, ToolCall $call, array $arguments): array
    {
        unset($call, $arguments, $agent);
        $rows = [];
        foreach ($this->clients->all() as $id => $client) {
            $rows[] = ['id' => $id, 'brief' => $client->brief()];
        }

        return ['servers' => $rows];
    }

    public function isReadonly(string $name, array $arguments): bool
    {
        unset($name, $arguments);

        return true;
    }
}
