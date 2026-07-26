<?php

declare(strict_types=1);

namespace Switon\Schedule\Command;

use Switon\Cli\ServerInterface;
use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Attribute\Scene;
use Switon\Core\ConsoleInterface;
use Switon\Core\Runtime;
use Switon\Schedule\ScheduleInterface;
use Switon\Schedule\TaskRunnerInterface;
use Swoole\Event;
use Swoole\Timer;

use function sprintf;

/**
 * CLI: schedule work, run, list, task actions.
 *
 * Road-signs:
 * - schedule:work
 * - schedule:run
 * - schedule:list
 * - schedule:task
 * - TaskRunner
 *
 * Use when operating scheduled tasks from the console in daemon, one-shot, inventory, or direct-run modes.
 *
 * @see \Switon\Schedule\TaskRunnerInterface
 * @see \Switon\Schedule\ScheduleInterface
 * @see \Switon\Cli\Handler
 */
#[Scene('schedule')]
class ScheduleCommand
{
    #[Autowired] protected AppInterface $app;
    #[Autowired] protected TaskRunnerInterface $runner;
    #[Autowired] protected ScheduleInterface $schedule;
    #[Autowired] protected ConsoleInterface $console;

    #[Autowired] protected int $tick = 1000;

    /** Show registered scheduled tasks. */
    public function defaultAction(): int
    {
        return $this->listAction();
    }

    /** Runs the long-lived scheduler loop with second-resolution ticks. */
    public function workAction(): int
    {
        if (!Runtime::isCoroutineEnabled()) {
            return $this->console->error(
                'The "schedule:work" command requires coroutine runtime support.',
                [],
                ServerInterface::EXIT_COMMAND_EXECUTION_FAILED
            );
        }

        Timer::tick(
            $this->tick,
            fn () => $this->runner->runTasks()
        );
        Event::wait();

        return 0;
    }

    /** Runs one schedule-matching pass. */
    public function runAction(): int
    {
        $this->runner->runTasks();
        if ($this->runner->hadFailures()) {
            return $this->console->error(
                'One or more scheduled tasks failed.',
                [],
                ServerInterface::EXIT_GENERAL_ERROR
            );
        }

        return 0;
    }

    /** Prints all registered tasks with lock TTL, timezone, expression, and class name. */
    public function listAction(): int
    {
        $rows = $this->schedule->all();
        if ($rows === []) {
            $this->console->writeLn('No scheduled tasks registered.');

            return 0;
        }

        $tzLabel = trim($this->app->timezone());
        if ($tzLabel === '') {
            $tzLabel = 'UTC';
        }
        foreach ($rows as $task) {
            $this->console->writeLn(
                sprintf(
                    '%s  lockTtl=%s  tz=%s  expr=%s  class=%s',
                    $task->name,
                    $task->lockTtl,
                    $tzLabel,
                    $task->expression,
                    $task->taskClass
                )
            );
        }

        return 0;
    }

    /** Runs a single task by name immediately, without evaluating its expression. */
    public function taskAction(string $name = ''): int
    {
        if ($name === '') {
            return $this->console->error(
                'Usage: schedule:task <name>',
                [],
                ServerInterface::EXIT_COMMAND_EXECUTION_FAILED
            );
        }

        $this->runner->runTaskNow($name);
        if ($this->runner->hadFailures()) {
            return $this->console->error(
                'Scheduled task failed: {name}',
                ['name' => $name],
                ServerInterface::EXIT_GENERAL_ERROR
            );
        }

        return 0;
    }
}
