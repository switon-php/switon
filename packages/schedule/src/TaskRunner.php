<?php

declare(strict_types=1);

namespace Switon\Schedule;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Core\Exception\MisuseException;
use Switon\Core\Runtime;
use Switon\Executor\ExecutorInterface;
use Switon\Schedule\Event\TaskFailed;
use Switon\Schedule\Event\TaskFinished;
use Switon\Schedule\Event\TaskStarted;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Throwable;

/**
 * Default runner: expression match, scope locks, executor dispatch, and events.
 *
 * Road-signs:
 * - runTasks batch + matches
 * - runTaskNow
 * - Expression
 * - positive lockTtl → LockInterface
 * - TaskStarted TaskFinished TaskFailed
 *
 * @see \Switon\Schedule\TaskRunnerInterface
 * @see \Switon\Schedule\ExpressionInterface
 * @see \Switon\Schedule\LockInterface
 * @see \Switon\Schedule\Event\TaskStarted
 * @see \Switon\Schedule\Event\TaskFinished
 * @see \Switon\Schedule\Event\TaskFailed
 */
class TaskRunner implements TaskRunnerInterface
{
    #[Autowired] protected ScheduleInterface $schedule;
    #[Autowired] protected ExpressionInterface $expression;
    #[Autowired] protected ClockInterface $clock;
    #[Autowired] protected ExecutorInterface $executor;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected AppInterface $app;
    #[Autowired] protected LockInterface $lock;

    protected bool $lastRunHadFailures = false;

    /** {@inheritDoc} */
    public function runTasks(): void
    {
        $this->lastRunHadFailures = false;
        $now = $this->clock->time();
        $batch = [];
        foreach ($this->schedule->all() as $task) {
            if (!$this->expression->matches($task->expression, $now, $this->effectiveTimezone())) {
                continue;
            }
            $batch[] = $task;
        }

        if ($batch === []) {
            return;
        }

        if (Runtime::isCoroutineEnabled()) {
            $wg = new WaitGroup();
            foreach ($batch as $task) {
                $wg->add();
                Coroutine::create(function () use ($wg, $task): void {
                    try {
                        $this->runSingle($task, false);
                    } finally {
                        $wg->done();
                    }
                });
            }
            $wg->wait();

            return;
        }

        foreach ($batch as $task) {
            $this->runSingle($task, false);
        }
    }

    /** {@inheritDoc} */
    public function runTaskNow(string $name): void
    {
        $this->lastRunHadFailures = false;
        $task = $this->schedule->get($name);
        if ($task === null) {
            MisuseException::raise('Unknown scheduled task "{name}"', ['name' => $name]);
        }

        $this->runSingle($task, true);
    }

    protected function runSingle(ScheduledTask $task, bool $immediate): void
    {
        $key = $this->lockKey($task);

        if ($task->lockTtl > 0) {
            if (!$this->lock->tryAcquire($key, $task->lockTtl)) {
                return;
            }
        }

        try {
            $this->eventDispatcher->dispatch(new TaskStarted($this, $task->name, $immediate));
            $this->executor->invoke($task->taskClass);
            $this->eventDispatcher->dispatch(new TaskFinished($this, $task->name, $immediate));
        } catch (Throwable $e) {
            $this->lastRunHadFailures = true;
            $this->eventDispatcher->dispatch(new TaskFailed($this, $task->name, $e));
        } finally {
            if ($task->lockTtl > 0) {
                $this->lock->release($key);
            }
        }
    }

    /** @return non-empty-string IANA id for expression wall-clock, or UTC if unset/blank. */
    protected function effectiveTimezone(): string
    {
        $tz = trim($this->app->timezone());

        return $tz !== '' ? $tz : 'UTC';
    }

    protected function lockKey(ScheduledTask $task): string
    {
        return sprintf('schedule:%s:%s', $this->app->id(), $task->name);
    }

    /** {@inheritDoc} */
    public function hadFailures(): bool
    {
        return $this->lastRunHadFailures;
    }
}
