<?php

declare(strict_types=1);

namespace Switon\Ai;

use Switon\Ai\Suspension\Interrupt;

/**
 * In-process tool (schema + call). `$name` is the wire id; `schema()` null disables.
 *
 * Prefer extending an abstract-tool base (`isReadonly` default false).
 *
 * Road-signs:
 * - Registry + dispatch: {@see \Switon\Tooling\ToolsInterface::call()} ({@see \Switon\Tooling\Tools})
 * - Base class: {@see \Switon\Tooling\AbstractTool}
 * - Remote MCP tools: {@see \Switon\McpClient\Tool\McpCallTool}
 * - Suspend: returning an {@see Interrupt} makes the loop persist it on the turn
 *
 * Guidance: the registry retains {@see ToolCall} bookkeeping; handlers receive only `$agent`
 * (workspace / spill root / charter), the live {@see ToolCall} (id / name / stop poll), and
 * arguments already bound by {@see \Switon\Tooling\ArgumentBinderInterface}.
 */
interface ToolInterface
{
    public function schema(string $name): ?ToolSchema;

    /**
     * Handler body. Return `string` / `array`, {@see ToolResult}, or {@see Interrupt};
     * registry wraps — {@see \Switon\Tooling\ToolsInterface::call()}.
     *
     * Guidance: poll {@see \Switon\Tooling\AbstractTool::isCancelled()} between chunks and exit early.
     *
     * @param array<string, mixed> $arguments Bound arguments ({@see \Switon\Tooling\ArgumentBinderInterface::bind()})
     */
    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed;

    /**
     * Transcript may omit this call and its result. Default false.
     *
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool;
}
