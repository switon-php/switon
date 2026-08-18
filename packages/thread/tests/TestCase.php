<?php

declare(strict_types=1);

namespace Switon\Thread\Tests;

use LogicException;
use Switon\Ai\Completion;
use Switon\Ai\Message;
use Switon\Ai\Thread;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Core\ClockInterface;
use Switon\Core\PathAliasInterface;
use Switon\Testing\TestCase as BaseTestCase;
use Switon\Thread\Threads;
use Switon\Thread\ThreadsInterface;

/**
 * Thread package unit tests with `@threads` temp binding helpers.
 */
abstract class TestCase extends BaseTestCase
{
    protected function threadStore(): ThreadsInterface
    {
        return $this->container->get(ThreadsInterface::class);
    }

    /** Test storage path under `@threads` (host id or `{host}.{leaf}`). */
    protected function threadDir(string $threadId): string
    {
        if (!str_contains($threadId, '.')) {
            return '@threads/' . $threadId;
        }

        [$host, $leaf] = explode('.', $threadId, 2);

        return '@threads/' . $host . '/subagents/' . $leaf;
    }

    protected function threadCreate(string $id): Thread
    {
        $thread = Thread::of($id, $this->threadDir($id));
        $this->threadStore()->create($thread);

        return $thread;
    }

    protected function threadLoad(string $id): Thread
    {
        $thread = Thread::of($id, $this->threadDir($id));
        $this->threadStore()->load($thread);

        return $thread;
    }

    protected function threadLoadState(string $id): array
    {
        return $this->threadLoad($id)->state;
    }

    /** @param array<string, mixed> $state */
    protected function threadSaveState(string $id, array $state): void
    {
        $this->threadStore()->saveState(new Thread($id, state: $state, datadir: $this->threadDir($id)));
    }

    protected function threadsImpl(): Threads
    {
        $threads = $this->threadStore();
        if (!$threads instanceof Threads) {
            throw new LogicException('ThreadsInterface must resolve to Threads in tests.');
        }

        return $threads;
    }

    /**
     * Test stand-in for the agent loop: open a turn on `$thread` when the tip is missing or
     * sealed, place `$completion` on it, then checkpoint. Production callers create the turn.
     */
    protected function threadAppend(Thread $thread, Completion $completion): void
    {
        $now = $this->container->get(ClockInterface::class)->now();
        $completion->landedAt = $now;
        if ($thread->turns === [] || $thread->lastTurn()->isSettled()) {
            $turn = Turn::of($completion->completeId, [$completion], TurnStatus::Running);
            $turn->openedAt = $now;
            $thread->turns[] = $turn;
        } elseif ($thread->lastTurn()->completions === [] || $thread->lastTurn()->lastCompletion() !== $completion) {
            $thread->lastTurn()->completions[] = $completion;
        }
        $this->threadStore()->saveTurn($thread);
    }

    /** Test stand-in for the agent: settle the tip, then land it. */
    protected function threadCommit(Thread $thread, TurnStatus $status = TurnStatus::Completed): void
    {
        $turn = $thread->lastTurn();
        $turn->status = $status;
        $this->threadStore()->commit($thread);
    }

    /** Point thread file layout at a temp root via `@threads`. */
    protected function bindThreadStorageRoot(string $root): void
    {
        foreach ([
            ThreadsInterface::class,
            Threads::class,
        ] as $id) {
            if ($this->container->has($id)) {
                $this->container->remove($id);
            }
        }

        $this->container->get(PathAliasInterface::class)->set('@threads', $root);
    }

    protected function threadLeafDir(string $threadId): string
    {
        return $this->container->get(PathAliasInterface::class)->resolve($this->threadDir($threadId));
    }

    /**
     * @param list<Completion> $completions
     *
     * @return list<Message>
     */
    protected function flattenWireMessages(array $completions, ?Completion $omit = null): array
    {
        $messages = [];
        foreach ($completions as $completion) {
            if ($omit !== null && $completion === $omit) {
                continue;
            }
            $messages[] = $completion->input;
            if ($completion->output !== null) {
                $messages[] = $completion->output;
            }
        }

        return $messages;
    }
}
