<?php

declare(strict_types=1);

namespace Switon\Tooling;

use Switon\Ai\AgentInterface;
use Switon\Ai\Suspension\Interrupt;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolInterface;
use Switon\Ai\ToolkitInterface;
use Switon\Ai\ToolResult;
use Switon\Ai\ToolSchema;

/**
 * Named tool catalog. Registered ids are the map keys; a toolkit claims a name only when no tool holds it.
 *
 * @see Tools
 */
interface ToolsInterface
{
    /**
     * Registered tool first, then toolkits. `null` means unknown or `schema()` returned null.
     */
    public function get(string $name): ?ToolSchema;

    /**
     * Schemas for this run, with `.caption` required. An unresolvable name is omitted and emits {@see Event\ToolMissing} once.
     *
     * @param list<string> $names
     *
     * @return array<string, ToolSchema>
     */
    public function pick(array $names): array;

    /**
     * Run `$schema->handler`. Arguments are already bound on `$call`.
     *
     * A plain return becomes {@see ToolResult}. An existing {@see ToolResult} is kept. {@see Interrupt} is returned as-is. A thrown `\Throwable` becomes the result content. An error result emits {@see Event\ToolFailed}, then {@see Event\ToolFinished}.
     */
    public function call(AgentInterface $agent, ToolCall $call, ToolSchema $schema): ToolResult|Interrupt;

    /**
     * {@see ToolInterface::isReadonly()}. Unknown names → false.
     *
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool;

    /**
     * @param bool $overwrite false = keep existing id (provider discovery); true = replace
     */
    public function addTool(string $name, ToolInterface $tool, bool $overwrite = false): void;

    /** Drop a tool registered under `$name`. Missing names are ignored. */
    public function removeTool(string $name): void;

    /**
     * Register under a label used only for removal and reload. A repeat replaces that label.
     */
    public function addToolkit(string $name, ToolkitInterface $toolkit): void;

    /** Remove the toolkit registered under `$name`. */
    public function removeToolkit(string $name): void;

    /** {@see ToolkitInterface::reload()} on every registered toolkit. */
    public function reload(): void;
}
