<?php

declare(strict_types=1);

namespace Switon\Ai;

use stdClass;

use function is_array;

/**
 * Agent tool catalog entry: `$definition` bag as the source of truth.
 *
 * Guidance: an agent tool passes itself as `$handler`. {@see of()} leaves `$handler` null
 * when the entry is catalog-only. LLM encoding reads `description` and `inputSchema`.
 */
class ToolSchema
{
    /** @var array<string, mixed> Tool definition bag */
    public array $definition;

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     */
    public function __construct(
        public ?ToolInterface $handler = null,
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
     * Full catalog entry as-is (no key stripping, no local handler).
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
     * JSON Schema for tool arguments (`parameters` / `input_schema`) — `$definition` `inputSchema` as-is;
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
