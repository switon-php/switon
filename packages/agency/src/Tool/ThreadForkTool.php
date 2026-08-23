<?php

declare(strict_types=1);

namespace Switon\Agency\Tool;

use Switon\Agency\Exception;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Thread\ThreadsInterface;
use Switon\Tooling\AbstractTool;

use function date;

/**
 * Tool `thread_fork`. Copies this host thread and returns the new id.
 * The copy is not started here. Opening it runs `start()`, which drops sub-agents and closes unsettled tool calls.
 *
 * The `agent_*` tool-name convention is reserved for profile-as-tool mounts ({@see \Switon\Agency\ProfilesInterface}).
 */
class ThreadForkTool extends AbstractTool
{
    #[Autowired] protected ThreadsInterface $threads;

    public function schema(string $name): ?ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Fork this host session into a new thread. Sub-agents on the branch are dropped; continue via the returned thread_id.',
            properties: [],
            required: [],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        if ($agent->parent() !== null) {
            Exception::raise('Fork requires a root agent session.');
        }

        $copy = $this->threads->fork($agent->thread());

        return [
            'thread_id' => $copy->id,
            'born_at' => date('Y-m-d H:i:s'),
        ];
    }
}
