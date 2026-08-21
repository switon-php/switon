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
use Switon\McpClient\Exception;

use function is_array;
use function is_string;

/**
 * Tool `mcp_schema`: one MCP tool's argument schema.
 */
class McpSchemaTool implements ToolInterface
{
    #[Autowired] protected ClientsInterface|Lazy $clients;

    protected ?ToolSchema $schema = null;

    public function schema(string $name): ?ToolSchema
    {
        unset($name);
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Argument schema for one MCP tool. Uses the in-memory catalog from mcp_tools.',
            properties: [
                'server' => [
                    'type' => 'string',
                    'description' => 'MCP server id (mcp.yml key).',
                ],
                'tool' => [
                    'type' => 'string',
                    'description' => 'Remote tool name on that server.',
                ],
            ],
            required: ['server', 'tool'],
        );
    }

    /** @return array{name: string, description: string, arguments: array<string, mixed>} */
    public function call(AgentInterface $agent, ToolCall $call, array $arguments): array
    {
        unset($call, $agent);
        $server = $arguments['server'] ?? null;
        $tool = $arguments['tool'] ?? null;
        if (!is_string($server) || $server === '') {
            Exception::raise('MCP server id must be a string.');
        }
        if (!is_string($tool) || $tool === '') {
            Exception::raise('MCP tool name must be a string.');
        }

        $catalog = $this->clients->get($server)->listTools(false);
        $schema = $catalog[$tool] ?? null;
        if (!$schema instanceof ToolSchema) {
            Exception::raise('Unknown MCP tool "{tool}" on "{server}".', ['tool' => $tool, 'server' => $server]);
        }
        $argumentsSchema = $schema->definition['inputSchema'] ?? ['type' => 'object', 'properties' => []];
        if (!is_array($argumentsSchema)) {
            $argumentsSchema = ['type' => 'object', 'properties' => []];
        }

        return [
            'name' => $tool,
            'description' => (string) ($schema->definition['description'] ?? ''),
            'arguments' => $argumentsSchema,
        ];
    }

    public function isReadonly(string $name, array $arguments): bool
    {
        unset($name, $arguments);

        return true;
    }
}
