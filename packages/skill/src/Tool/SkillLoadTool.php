<?php

declare(strict_types=1);

namespace Switon\Skill\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\Exception\ToolInvalidArgumentException;
use Switon\Ai\Part\PinnedPart;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Skill\SkillsInterface;
use Switon\Tooling\AbstractTool;

/** Tool `skill_load`. The composed block is a {@see PinnedPart}; `$content` stays empty so the provider sees it once. */
class SkillLoadTool extends AbstractTool
{
    #[Autowired] protected SkillsInterface $skills;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Load a configured skill by id. Returns the composed skill block: a [skill:id] header, a Skill root line, and the SKILL.md body. Paths in the body (references/…, scripts/…) are relative to that root.',
            properties: [
                'id' => [
                    'type' => 'string',
                    'description' => 'Skill id from the configured skills catalog.',
                ],
            ],
            required: ['id'],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function call(AgentInterface $agent, ToolCall $call, array $arguments): ToolResult
    {
        $skillId = $arguments['id'];
        if (!$this->skills->has($skillId)) {
            ToolInvalidArgumentException::raise('Skill "{id}" does not exist.', ['id' => $skillId]);
        }

        return new ToolResult('', parts: [new PinnedPart('skill:' . $skillId, $this->skills->block($skillId))]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return true;
    }
}
