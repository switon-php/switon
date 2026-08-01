<?php

declare(strict_types=1);

namespace Switon\Mcp;

use stdClass;

use function is_array;

/**
 * MCP tool catalog entry: `$definition` bag as the source of truth.
 *
 * Guidance: describe the tool for MCP clients only. Invocation goes through
 * {@see ToolInterface::call()} — this type has no local handler.
 */
class ToolSchema
{
    /** @var array<string, mixed> MCP tool definition */
    public array $definition;

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     */
    public function __construct(
        string $description = '',
        array  $properties = [],
        array  $required = [],
        bool   $additionalProperties = true,
    ) {
        $inputSchema = [
            'type' => 'object',
            'properties' => $properties,
        ];
        if ($required !== []) {
            $inputSchema['required'] = $required;
        }
        if (!$additionalProperties) {
            $inputSchema['additionalProperties'] = false;
        }

        $this->definition = [
            'description' => $description,
            'inputSchema' => $inputSchema,
        ];
    }

    /**
     * Full catalog entry as-is (no key stripping).
     *
     * @param array<string, mixed> $definition
     */
    public static function of(array $definition): static
    {
        $schema = new static();
        $schema->definition = $definition;

        return $schema;
    }

    /**
     * JSON Schema for tool arguments — `$definition` `inputSchema` as-is;
     * empty `properties` becomes `stdClass` so JSON encodes as `{}` rather than `[]`.
     *
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        $schema = $this->definition['inputSchema'] ?? null;
        if (!is_array($schema)) {
            return [
                'type' => 'object',
                'properties' => new stdClass(),
            ];
        }

        $out = $schema;
        if (!isset($out['type'])) {
            $out['type'] = 'object';
        }

        $properties = $out['properties'] ?? [];
        if ($properties instanceof stdClass) {
            return $out;
        }
        if (!is_array($properties) || $properties === []) {
            $out['properties'] = new stdClass();
        }

        return $out;
    }
}
