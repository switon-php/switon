<?php

declare(strict_types=1);

namespace Switon\Skill\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Skill\SkillsInterface;
use Switon\Tooling\AbstractTool;

/**
 * Tool `skill_list`. Ids and descriptions from the catalog, optionally filtered by keywords.
 *
 * Guidance: this is the index; load a body with `skill_load`.
 */
class SkillListTool extends AbstractTool
{
    #[Autowired] protected SkillsInterface $skills;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'List configured skills as id and description.',
            properties: [
                'keywords' => [
                    'type' => 'array',
                    'description' => 'Match id or description. Any one keyword is enough. Omit to list every skill.',
                    'items' => ['type' => 'string'],
                ],
            ],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<array{id: string, description: string}>
     */
    public function call(AgentInterface $agent, ToolCall $call, array $arguments): array
    {
        return $this->skills->list($arguments['keywords'] ?? []);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return true;
    }
}
