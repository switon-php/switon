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

use function array_key_exists;
use function in_array;
use function is_array;
use function is_string;

/**
 * Tool `mcp_tools`: one server's tool names and flat argument names.
 */
class McpToolsTool implements ToolInterface
{
    /** `params` value when a tool's arguments are nested. */
    protected const SCHEMA = 'Params structure is complex. Read with mcp_schema.';

    #[Autowired] protected ClientsInterface|Lazy $clients;

    protected ?ToolSchema $schema = null;

    public function schema(string $name): ?ToolSchema
    {
        unset($name);
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'List one MCP server\'s tools. `params` is the flat argument names, `[]` when there are none, or `Params structure is complex. Read with mcp_schema.` when an argument is nested. Fetches once; reload forces another fetch of that server.',
            properties: [
                'server' => [
                    'type' => 'string',
                    'description' => 'MCP server id (mcp.yml key).',
                ],
                'reload' => [
                    'type' => 'boolean',
                    'description' => 'Fetch this server\'s tool list again. Omit to keep the in-memory list.',
                    'default' => false,
                ],
            ],
            required: ['server'],
        );
    }

    /** @return array{server: string, tools: list<array{name: string, description: string, params: list<string>|string}>} */
    public function call(AgentInterface $agent, ToolCall $call, array $arguments): array
    {
        unset($call, $agent);
        $server = $arguments['server'] ?? null;
        if (!is_string($server) || $server === '') {
            Exception::raise('MCP server id must be a string.');
        }

        $catalog = $this->clients->get($server)->listTools(($arguments['reload'] ?? false) === true);
        $rows = [];
        foreach ($catalog as $name => $schema) {
            if (!$schema instanceof ToolSchema) {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'description' => (string) ($schema->definition['description'] ?? ''),
                'params' => $this->params($schema->definition['inputSchema'] ?? null),
            ];
        }

        return [
            'server' => $server,
            'tools' => $rows,
        ];
    }

    public function isReadonly(string $name, array $arguments): bool
    {
        unset($name, $arguments);

        return true;
    }

    /** @return list<string>|string {@see self::SCHEMA} when an argument is nested. */
    protected function params(mixed $schema): array|string
    {
        if ($schema === null) {
            return [];
        }
        if (!is_array($schema) || !$this->isFlatObject($schema)) {
            return self::SCHEMA;
        }

        $names = [];
        foreach ($schema['properties'] ?? [] as $name => $property) {
            if (!is_string($name) || !is_array($property) || !$this->isFlatProperty($property)) {
                return self::SCHEMA;
            }
            $names[] = $name;
        }

        return $names;
    }

    /** @param array<string, mixed> $schema */
    protected function isFlatObject(array $schema): bool
    {
        foreach (['oneOf', 'anyOf', 'allOf', '$ref', 'not', 'patternProperties'] as $key) {
            if (isset($schema[$key])) {
                return false;
            }
        }
        if (array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] !== false) {
            return false;
        }

        return ($schema['type'] ?? 'object') === 'object'
            && (!array_key_exists('properties', $schema) || is_array($schema['properties']));
    }

    /** @param array<string, mixed> $property */
    protected function isFlatProperty(array $property): bool
    {
        foreach (['properties', 'oneOf', 'anyOf', 'allOf', '$ref', 'not', 'patternProperties'] as $key) {
            if (isset($property[$key])) {
                return false;
            }
        }
        if (array_key_exists('additionalProperties', $property) && $property['additionalProperties'] !== false) {
            return false;
        }
        if (isset($property['items'])) {
            $items = $property['items'];

            return ($property['type'] ?? null) === 'array' && is_array($items) && $this->isScalar($items);
        }

        return $this->isScalar($property);
    }

    /** @param array<string, mixed> $schema */
    protected function isScalar(array $schema): bool
    {
        if (isset($schema['enum'])) {
            if (!is_array($schema['enum'])) {
                return false;
            }
            foreach ($schema['enum'] as $value) {
                if (is_array($value)) {
                    return false;
                }
            }
        }
        if (!isset($schema['type'])) {
            return isset($schema['enum']);
        }
        $type = $schema['type'];
        if (is_string($type)) {
            return in_array($type, ['string', 'number', 'integer', 'boolean', 'null'], true);
        }
        if (!is_array($type) || $type === []) {
            return false;
        }
        foreach ($type as $item) {
            if (!is_string($item) || !in_array($item, ['string', 'number', 'integer', 'boolean', 'null'], true)) {
                return false;
            }
        }

        return true;
    }
}
