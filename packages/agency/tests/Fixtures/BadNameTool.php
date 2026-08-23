<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Fixtures;

use Switon\Tooling\AbstractTool;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;

class BadNameTool extends AbstractTool
{
    public function schema(string $name): ToolSchema
    {
        return new ToolSchema(
            $this,
            description: 'Invalid tool name.',
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        return [];
    }
}
