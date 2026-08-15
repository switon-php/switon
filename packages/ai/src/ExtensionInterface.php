<?php

declare(strict_types=1);

namespace Switon\Ai;

/**
 * Agent lifecycle extension point from inbound letter through turn, model step, and tool call.
 *
 * Guidance: mutate the supplied object; `reviewTool()` alone answers whether the call needs review.
 *
 * Extend {@see AbstractExtension}; {@see \Switon\Agency\ExtensionsInterface} fans registrations
 * into one implementation.
 */
interface ExtensionInterface
{
    /** The letter as it arrives; no turn exists yet. */
    public function inbound(AgentInterface $agent, Envelope $envelope): void;

    /** A turn is about to open and join the thread — set `$turn->attributes` here. */
    public function beforeTurn(AgentInterface $agent, Turn $turn): void;

    /** The turn is sealed, whichever status it landed on. */
    public function afterTurn(AgentInterface $agent, Turn $turn): void;

    /** Before each model request — reshape `$charter` (prompt / tools). */
    public function beforeModel(AgentInterface $agent, Charter $charter): void;

    /** After each model response, before its tool calls run. */
    public function afterModel(AgentInterface $agent, Completion $completion): void;

    /**
     * Before one tool call that is about to run — side effects only (audit, rewrite `$call->arguments`).
     *
     * {@see reviewTool()} is asked first. A call that suspends for approval does not reach here.
     */
    public function beforeTool(AgentInterface $agent, ToolCall $call): void;

    /**
     * Whether this call needs host review before it runs — `true` suspends on
     * {@see Suspension\ToolReviewInterrupt}, `false` runs straight through.
     *
     * Not asked again once the host approves that call.
     */
    public function reviewTool(AgentInterface $agent, ToolCall $call): bool;

    /**
     * After one tool call — reshape `$result`.
     *
     * Only for calls that reached execution: mail rejected by the charter, or never approved,
     * never gets here.
     */
    public function afterTool(AgentInterface $agent, ToolResult $result): void;
}
