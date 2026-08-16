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
use Switon\Ai\ToolTiming;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Tooling\Event\ToolFailed;
use Switon\Tooling\Event\ToolFinished;
use Switon\Tooling\Event\ToolMissing;
use Switon\Tooling\Event\ToolStarted;
use Switon\Tooling\Tool\BashTool;
use Switon\Tooling\Tool\EditsTool;
use Switon\Tooling\Tool\EditTool;
use Switon\Tooling\Tool\GlobTool;
use Switon\Tooling\Tool\GrepTool;
use Switon\Tooling\Tool\LintTool;
use Switon\Tooling\Tool\LsTool;
use Switon\Tooling\Tool\MediaTool;
use Switon\Tooling\Tool\ReadTool;
use Switon\Tooling\Tool\RecallTool;
use Switon\Tooling\Tool\RmTool;
use Switon\Tooling\Tool\SleepTool;
use Switon\Tooling\Tool\WriteTool;
use Throwable;

use function is_array;

/**
 * Named tool catalog. A registered id wins over a toolkit claim.
 *
 * Guidance: `extra.switon.tools` only adds a free name; `tools` on this service replaces a name, and `null` drops it.
 *
 * @see ToolsInterface
 */
class Tools implements ToolsInterface
{
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected ClockInterface $clock;

    /**
     * Wire id → handler. Config `tools` replaces a name; `null` removes it.
     *
     * @var array<string, ToolInterface>
     */
    #[Autowired(instances: true)] protected array $tools = [
        'bash' => BashTool::class,
        'read' => ReadTool::class,
        'recall' => RecallTool::class,
        'write' => WriteTool::class,
        'edit' => EditTool::class,
        'edits' => EditsTool::class,
        'lint' => LintTool::class,
        'rm' => RmTool::class,
        'grep' => GrepTool::class,
        'glob' => GlobTool::class,
        'ls' => LsTool::class,
        'media' => MediaTool::class,
        'sleep' => SleepTool::class,
    ];

    /**
     * Label → toolkit. The label is for removal and reload; `schema()` names the tool.
     *
     * @var array<string, ToolkitInterface>
     */
    #[Autowired(instances: true)] protected array $toolkits = [
        'exec' => ExecToolkit::class,
    ];

    public function pick(array $names): array
    {
        /** @var array<string, ToolSchema> $tools */
        $tools = [];
        $missing = [];
        foreach ($names as $name) {
            if (isset($tools[$name]) || isset($missing[$name])) {
                continue;
            }

            $schema = $this->get($name);
            if ($schema === null) {
                $missing[$name] = true;
                $this->eventDispatcher->dispatch(new ToolMissing($this, $name));
                continue;
            }

            $tools[$name] = $schema;
        }

        foreach ($tools as $tool) {
            $input = $tool->definition['inputSchema'] ?? null;
            if (!is_array($input) || isset($input['properties']['.caption'])) {
                continue;
            }

            $input['properties']['.caption'] = [
                'type' => 'string',
                'description' => "Ultra-short tool-line label (verb + object), not a full sentence; user's language.",
                'maxLength' => 32,
            ];
            $input['required'][] = '.caption';
            $tool->definition['inputSchema'] = $input;
        }

        return $tools;
    }

    public function call(AgentInterface $agent, ToolCall $call, ToolSchema $schema): ToolResult|Interrupt
    {
        $this->eventDispatcher->dispatch(new ToolStarted($this, $call, $agent));
        $started = $this->clock->microtime();

        try {
            $returned = $schema->handler->call($agent, $call, $call->arguments);
        } catch (Throwable $e) {
            $returned = $e;
        }

        // Interrupt = suspend; Loop emits TurnSuspended. Do not pretend the tool finished.
        if ($returned instanceof Interrupt) {
            return $returned;
        }

        $result = $returned instanceof ToolResult ? $returned : ToolResult::of($call, $returned);
        $result->id = $call->id;
        $result->name = $call->name;
        $result->timing ??= ToolTiming::of($call, $this->clock->elapsed($started));

        if ($result->isError) {
            $this->eventDispatcher->dispatch(new ToolFailed($this, $call, $agent, $result));
        }

        $this->eventDispatcher->dispatch(new ToolFinished($this, $call, $agent, $result));

        return $result;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        $schema = $this->get($name);

        return $schema === null ? false : $schema->handler->isReadonly($name, $arguments);
    }

    public function addTool(string $name, ToolInterface $tool, bool $overwrite = false): void
    {
        if (!$overwrite && isset($this->tools[$name])) {
            return;
        }

        $this->tools[$name] = $tool;
    }

    public function removeTool(string $name): void
    {
        unset($this->tools[$name]);
    }

    public function addToolkit(string $name, ToolkitInterface $toolkit): void
    {
        $this->toolkits[$name] = $toolkit;
    }

    public function removeToolkit(string $name): void
    {
        unset($this->toolkits[$name]);
    }

    public function reload(): void
    {
        foreach ($this->toolkits as $toolkit) {
            $toolkit->reload();
        }
    }

    public function get(string $name): ?ToolSchema
    {
        $tool = $this->tools[$name] ?? null;
        if ($tool !== null) {
            return $tool->schema($name);
        }

        foreach ($this->toolkits as $toolkit) {
            $schema = $toolkit->schema($name);
            if ($schema !== null) {
                return $schema;
            }
        }

        return null;
    }
}
