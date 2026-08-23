<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Fixtures;

use Switon\Tooling\AbstractTool;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;

/** Tool whose {@see schema()} returns `null`. */
class DisabledTool extends AbstractTool
{
    public function schema(string $name): ?ToolSchema
    {
        return null;
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        return 'should not run';
    }
}
