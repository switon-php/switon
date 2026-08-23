<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Fixtures;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolInterface;
use Switon\Ai\ToolSchema;
use Switon\Tooling\AbstractTool;

/** Records calls while delegating to a real tool. */
class RecordingTool extends AbstractTool
{
    /** Global call order across recorders, so tests can rebuild chronological tool-call lists. */
    public static int $sequence = 0;

    /** @var list<array{seq: int, name: string, arguments: array<string, mixed>, result: mixed}> */
    public array $calls = [];

    public function __construct(protected readonly ToolInterface $inner)
    {
    }

    public function schema(string $name): ?ToolSchema
    {
        $schema = $this->inner->schema($name);
        if ($schema === null) {
            return null;
        }

        // Charter dispatch calls `$schema->handler`. The inner schema points at the inner tool,
        // so a straight return would run the tool and skip this recorder.
        $wrapped = ToolSchema::of($schema->definition);
        $wrapped->handler = $this;

        return $wrapped;
    }

    public function isReadonly(string $name, array $arguments): bool
    {
        return $this->inner->isReadonly($name, $arguments);
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $result = $this->inner->call($agent, $call, $arguments);
        $this->calls[] = ['seq' => ++self::$sequence, 'name' => $call->name, 'arguments' => $arguments, 'result' => $result];

        return $result;
    }
}
