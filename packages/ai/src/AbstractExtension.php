<?php

declare(strict_types=1);

namespace Switon\Ai;

/**
 * No-op {@see ExtensionInterface}; override only the points you care about.
 * Guidance: `reviewTool()` returns false by default, so tools run without host review.
 */
abstract class AbstractExtension implements ExtensionInterface
{
    public function inbound(AgentInterface $agent, Envelope $envelope): void
    {
    }

    public function beforeTurn(AgentInterface $agent, Turn $turn): void
    {
    }

    public function afterTurn(AgentInterface $agent, Turn $turn): void
    {
    }

    public function beforeModel(AgentInterface $agent, Charter $charter): void
    {
    }

    public function afterModel(AgentInterface $agent, Completion $completion): void
    {
    }

    public function beforeTool(AgentInterface $agent, ToolCall $call): void
    {
    }

    public function reviewTool(AgentInterface $agent, ToolCall $call): bool
    {
        return false;
    }

    public function afterTool(AgentInterface $agent, ToolResult $result): void
    {
    }
}
