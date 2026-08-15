<?php

declare(strict_types=1);

namespace Switon\Ai;

use Switon\Ai\Suspension\Interrupt;
use Throwable;

/**
 * Host-facing contract for one agent in a delegation tree.
 *
 * Road-signs:
 * - Drive: `deliver()` queues, `run()` blocks; both land on the inbox worker
 * - Suspend: tool review / external reply / retry raise {@see Interrupt}; resume lands a {@see Suspension\Continuation}
 * - Context: `thread()` is stored by {@see \Switon\Thread\ThreadsInterface}; overflow cleanup is {@see \Switon\Thread\ExcerptExtension}
 * - Identity: `profile()` comes from the Markdown catalog ({@see \Switon\Agency\ProfilesInterface}); `spawn()` = as-tool workers
 *
 * @see \Switon\Agency\Agent
 */
interface AgentInterface
{
    /** Full thread id (host UUID or `host.leaf`). */
    public function threadId(): string;

    /** Context history the agent reasons over. */
    public function thread(): Thread;

    /** Charter for the current delivery; all readers share one live instance so {@see Charter::invalidate()} reaches the loop. */
    public function charter(): Charter;

    /** Delegation depth (root = 0). */
    public function depth(): int;

    /** Delegating parent; null on root. */
    public function parent(): ?AgentInterface;

    /** Root of the delegation tree (self on root). */
    public function root(): AgentInterface;

    /** Tool sandbox. An empty value on a delegated agent uses the root's. */
    public function workspace(): string;

    public function profile(): Profile;

    /**
     * Swap the profile and persist the choice.
     *
     * @throws \Switon\Core\Exception Unknown profile id.
     */
    public function switchProfile(Profile $profile): void;

    /** Spawn delegated worker; its inbox starts immediately. Optional leaf (`t10001`…), null allocates under `root()`. */
    public function spawn(Profile $profile, ?string $leaf = null): static;

    /** Open the inbox worker once after the host creates or resumes the agent. */
    public function start(): void;

    /**
     * Enqueue one inbox envelope (fire-and-forget); requires `start()` first.
     *
     * @throws \Switon\Core\Exception Inbox full.
     */
    public function deliver(Envelope $envelope): void;

    /**
     * Enqueue and block until this delivery finishes (requires `start()` first); return its {@see Turn}
     * when one remains (tip = {@see Turn::lastCompletion()}; tool {@see Interrupt} → {@see Turn::$interrupt}).
     * {@see DiscardTurnLetter} may leave an empty conversation — then `null`.
     *
     * @throws \Switon\Core\Exception Inbox full.
     * @throws Throwable Delivery failure or cancel.
     */
    public function run(Envelope $envelope): ?Turn;

    /** Abort in-flight run; blocks until worker quiescent. Inbox stays open. */
    public function cancel(): void;

    public function isCancelled(): bool;

    /** Receive one {@see StreamDelta} while this agent is streaming. */
    public function onDelta(StreamDelta $delta): void;

    /** This worker and every spawned child are blocked waiting for the next letter. */
    public function isIdle(): bool;

    /** Mark cancel, close the inbox, and fail queued sync runs. Does not wait for the in-flight tool. */
    public function shutdown(): void;
}
