<?php

declare(strict_types=1);

namespace Switon\Thread;

use Switon\Ai\Thread;

/**
 * Persistence for a {@see Thread}.
 *
 * Contract: {@see Event\TurnCommitted} fires only when {@see self::commit()} seals a stored
 * thread — a memory-only seal dispatches nothing. {@see Event\CompletionAppended} is not
 * emitted here; the caller that landed the completion dispatches it after {@see self::saveTurn()}.
 *
 * @see \Switon\Thread\Threads
 */
interface ThreadsInterface
{
    /**
     * Birth this thread in place.
     *
     * Guidance: the leaf must be fresh — re-birthing an existing one resets its transcript
     * and state without merging; that is a caller bug.
     */
    public function create(Thread $thread): void;

    /**
     * Read this thread back in place.
     *
     * @throws \Switon\Thread\Exception\ThreadNotFoundException No leaf exists at `datadir`.
     */
    public function load(Thread $thread): void;

    /**
     * Copy this thread under a new id. Memory-only threads are deep-copied in place; a stored
     * thread also gets its whole leaf directory copied, while the returned object keeps the
     * turns the caller had loaded.
     */
    public function fork(Thread $source): Thread;

    public function saveState(Thread $thread): void;

    /**
     * Replace `turn.json` with the unsealed tip.
     *
     * Guidance: the caller places the completion and stamps `landedAt`; an empty thread or a settled tip is a caller bug.
     */
    public function saveTurn(Thread $thread): void;

    /**
     * Replace `conversation.jsonl` with the settled turns held in memory.
     *
     * Guidance: an unsealed tip is omitted, and `turn.json` is left untouched.
     */
    public function replaceConversation(Thread $thread): void;

    /**
     * Append the last turn to `conversation.jsonl` and the transcript, then delete `turn.json`.
     *
     * Guidance: rolls usage onto the turn and stamps `closedAt` before writing. A memory-only
     * thread is sealed the same way and dispatches nothing.
     */
    public function commit(Thread $thread): void;
}
