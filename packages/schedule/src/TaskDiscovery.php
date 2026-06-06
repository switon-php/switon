<?php

declare(strict_types=1);

namespace Switon\Schedule;

use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClassScannerInterface;
use Switon\Core\RunnerInterface;
use Switon\Schedule\Attribute\Scheduled;

/**
 * Discovers scheduled tasks from application scan paths and framework package Composer extra metadata.
 *
 * Guidance: Framework package tasks belong in composer.json `extra.switon.tasks`; app tasks under `@app/Task` and `@app/Areas/{area}/Task` are scanned automatically.
 *
 * Road-signs:
 * - app: {@see ClassScannerInterface} + <code>$tasks</code> + class-level {@see Scheduled}
 * - packages: {@see ComposerExtraInterface::collect()} <code>switon.tasks</code>
 * - register {@see ScheduleInterface::register()}
 *
 * @see \Switon\Schedule\TaskDiscoveryInterface
 * @see \Switon\Core\ClassScannerInterface
 * @see \Switon\ComposerExtra\ComposerExtraInterface
 * @see \Switon\Schedule\ScheduleInterface::register()
 * @see \Switon\Schedule\Attribute\Scheduled
 */
class TaskDiscovery implements TaskDiscoveryInterface
{
    #[Autowired] protected ClassScannerInterface $classScanner;
    #[Autowired] protected ComposerExtraInterface $composerExtra;
    #[Autowired] protected ScheduleInterface $schedule;
    /** @var array<string, string> */
    #[Autowired] protected array $tasks
        = [
            '@app/Task/*Task.php' => 'App\\Task\\*Task',
            '@app/Areas/*/Task/*Task.php' => 'App\\Areas\\*\\Task\\*Task',
        ];

    /** {@inheritDoc} */
    public function discover(): void
    {
        foreach ($this->classScanner->scan($this->tasks, Scheduled::class) as $className) {
            /** @var class-string<RunnerInterface> $className */
            $this->schedule->register($className);
        }

        foreach ($this->composerExtra->collect('switon.tasks') as $class) {
            /** @var class-string<RunnerInterface> $class */
            $this->schedule->register($class);
        }
    }
}
