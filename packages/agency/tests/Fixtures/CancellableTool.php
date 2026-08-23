<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Fixtures;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Tooling\AbstractTool;

/**
 * Test-only tool that honours per-call cancellation like {@see \Switon\Tooling\Tool\BashTool}.
 *
 * Returns `cancelled: true` for a call flagged `$call->cancelled`, and a normal result otherwise,
 * so batch-dispatch behavior can be asserted without spawning a process.
 */
class CancellableTool extends AbstractTool
{
    /** @var list<string> Call ids in execution order. */
    public static array $executed = [];

    public static function reset(): void
    {
        self::$executed = [];
    }

    public function schema(string $name): ToolSchema
    {
        return new ToolSchema($this, description: 'Test-only cancellable tool.');
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        self::$executed[] = $call->id;

        if ($call->cancelled) {
            return ['cancelled' => true, 'stdout' => ''];
        }

        return ['cancelled' => false, 'stdout' => 'ok'];
    }
}
