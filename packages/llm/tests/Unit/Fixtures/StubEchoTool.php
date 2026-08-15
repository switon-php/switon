<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit\Fixtures;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolInterface;
use Switon\Ai\ToolSchema;

/** @internal Echo tool for {@see InstructorTest}. */
class StubEchoTool implements ToolInterface
{
    public function schema(string $name): ?ToolSchema
    {
        return new ToolSchema(
            $this,
            description: 'Echo a message.',
            properties: [
                'message' => ['type' => 'string'],
            ],
            required: ['message'],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        return ['echo' => $arguments['message'] ?? ''];
    }

    public function isReadonly(string $name, array $arguments): bool
    {
        return false;
    }
}
