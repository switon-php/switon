<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Fixtures;

use Switon\Ai\ExtensionInterface;
use Switon\Agency\ExtensionsInterface;
use Switon\Ai\AgentInterface;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Envelope;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Ai\Turn;

/**
 * Mutable test registry: start empty, push the extensions a test needs.
 *
 * The container freezes a service on first `get()`, so replacing the whole binding mid-test is
 * unreliable — this instance stays the same and only its rows change.
 */
class MutableExtensionRegistry implements ExtensionsInterface
{
    /** @var list<ExtensionInterface> */
    public array $extensions = [];

    public function __construct(ExtensionInterface ...$extensions)
    {
        $this->extensions = array_values($extensions);
    }

    public function set(ExtensionInterface ...$extensions): void
    {
        $this->extensions = array_values($extensions);
    }

    public function all(): array
    {
        return $this->extensions;
    }

    public function discover(): void
    {
    }

    public function inbound(AgentInterface $agent, Envelope $envelope): void
    {
        foreach ($this->extensions as $extension) {
            $extension->inbound($agent, $envelope);
        }
    }

    public function beforeTurn(AgentInterface $agent, Turn $turn): void
    {
        foreach ($this->extensions as $extension) {
            $extension->beforeTurn($agent, $turn);
        }
    }

    public function afterTurn(AgentInterface $agent, Turn $turn): void
    {
        foreach ($this->extensions as $extension) {
            $extension->afterTurn($agent, $turn);
        }
    }

    public function beforeModel(AgentInterface $agent, Charter $charter): void
    {
        foreach ($this->extensions as $extension) {
            $extension->beforeModel($agent, $charter);
        }
    }

    public function afterModel(AgentInterface $agent, Completion $completion): void
    {
        foreach ($this->extensions as $extension) {
            $extension->afterModel($agent, $completion);
        }
    }

    public function beforeTool(AgentInterface $agent, ToolCall $call): void
    {
        foreach ($this->extensions as $extension) {
            $extension->beforeTool($agent, $call);
        }
    }

    public function reviewTool(AgentInterface $agent, ToolCall $call): bool
    {
        foreach ($this->extensions as $extension) {
            if ($extension->reviewTool($agent, $call)) {
                return true;
            }
        }

        return false;
    }

    public function afterTool(AgentInterface $agent, ToolResult $result): void
    {
        foreach ($this->extensions as $extension) {
            $extension->afterTool($agent, $result);
        }
    }
}
