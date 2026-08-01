<?php

declare(strict_types=1);

namespace Switon\Mcp;

use Switon\Core\Attribute\Autowired;

/**
 * MCP tool catalog from an explicit {@see ToolInterface} map.
 *
 * @see \Switon\Mcp\ToolsInterface
 */
class Tools implements ToolsInterface
{
    /** @var array<string, ToolInterface> */
    #[Autowired(instances: true)] protected array $tools = [];

    /** @return array<string, ToolInterface> */
    public function all(): array
    {
        $out = [];
        foreach ($this->tools as $name => $tool) {
            if ($tool->schema($name) === null) {
                continue;
            }

            $out[$name] = $tool;
        }

        return $out;
    }

    public function get(string $name): ?ToolInterface
    {
        $tool = $this->tools[$name] ?? null;
        if ($tool === null || $tool->schema($name) === null) {
            return null;
        }

        return $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function add(string $name, ToolInterface $tool): void
    {
        $this->tools[$name] = $tool;
    }
}
