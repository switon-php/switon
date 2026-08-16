<?php

declare(strict_types=1);

namespace Switon\Tooling\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Tooling\AbstractTool;

/** Tool `sleep`. In-process delay — no shell. */
class SleepTool extends AbstractTool
{
    #[Autowired] protected ClockInterface $clock;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Wait in-process (backoff/timers).',
            properties: [
                'seconds' => [
                    'type' => 'number',
                    'description' => 'Non-zero delay in seconds.',
                    'exclusiveMinimum' => 0,
                ],
            ],
            required: ['seconds'],
        );
    }


    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $seconds = (float)$arguments['seconds'];

        $started = $this->clock->microtime();
        $deadline = $started + $seconds;
        while ($this->clock->microtime() < $deadline) {
            if ($agent->isCancelled() || $call->cancelled) {
                return [
                    'started_at' => gmdate('c', (int)$started),
                    'woke_at' => gmdate('c', (int)$this->clock->microtime()),
                    'cancelled' => true,
                ];
            }

            $remaining = $deadline - $this->clock->microtime();
            $this->clock->sleep(min(0.1, $remaining));
        }

        return [
            'started_at' => gmdate('c', (int)$started),
            'woke_at' => gmdate('c', (int)$this->clock->microtime()),
        ];
    }
    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return true;
    }

}
