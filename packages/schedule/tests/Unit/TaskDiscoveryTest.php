<?php

declare(strict_types=1);

namespace Switon\Schedule\Tests\Unit;

use App\Task\AttributedScheduleTask;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\ClassScannerInterface;
use Switon\Core\FilesystemInterface;
use Switon\Schedule\Attribute\Scheduled;
use Switon\Schedule\ScheduleInterface;
use Switon\Schedule\TaskDiscovery;
use Switon\Schedule\Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TaskDiscoveryTest extends TestCase
{
    protected ScheduleInterface&MockObject $schedule;
    protected FilesystemInterface&MockObject $filesystem;

    protected function setUpContainer(): void
    {
        $composerExtra = $this->createMock(ComposerExtraInterface::class);
        $composerExtra->method('all')->willReturn([]);
        $composerExtra->method('collect')->willReturn([]);
        $this->container->replace(ComposerExtraInterface::class, $composerExtra);

        parent::setUpContainer();
        $this->container->get(\Switon\Core\PathAliasInterface::class)->set('@app', '/app');
        $this->schedule = $this->createMock(ScheduleInterface::class);
        $this->filesystem = $this->createMock(FilesystemInterface::class);
        $this->container->set(ScheduleInterface::class, $this->schedule);
        $this->container->set(FilesystemInterface::class, $this->filesystem);
    }

    protected function makeDiscovery(
        ?array                  $tasks = null,
        ?ClassScannerInterface  $classScanner = null,
        ?ComposerExtraInterface $composerExtra = null,
        ?ScheduleInterface      $schedule = null
    ): TestableTaskDiscovery {
        $parameters = [];
        if ($classScanner !== null) {
            $parameters['classScanner'] = $classScanner;
        }
        if ($composerExtra !== null) {
            $parameters['composerExtra'] = $composerExtra;
        }
        if ($schedule !== null) {
            $parameters['schedule'] = $schedule;
        }

        $discovery = $this->make(TestableTaskDiscovery::class, $parameters);
        if ($tasks !== null) {
            $discovery->setTasks($tasks);
        }

        return $discovery;
    }

    public function testDiscoverScansAndRegistersComposerTasks(): void
    {
        $classScanner = $this->createMock(ClassScannerInterface::class);
        $classScanner->expects($this->once())
            ->method('scan')
            ->with(
                [
                    '@app/Task/*Task.php' => 'App\\Task\\*Task',
                    '@app/Areas/*/Task/*Task.php' => 'App\\Areas\\*\\Task\\*Task',
                ],
                Scheduled::class
            )
            ->willReturn([]);

        $composerExtra = $this->createMock(ComposerExtraInterface::class);
        $composerExtra->expects($this->once())
            ->method('collect')
            ->with('switon.tasks')
            ->willReturn([
                'Switon\\Schedule\\Tests\\Fixtures\\GoodTask',
                'Switon\\Schedule\\Tests\\Fixtures\\SecondTask',
            ]);

        $schedule = $this->createMock(ScheduleInterface::class);
        $added = [];
        $schedule->expects($this->exactly(2))
            ->method('register')
            ->willReturnCallback(static function (string $class) use (&$added): void {
                $added[] = $class;
            });

        $this->makeDiscovery(
            classScanner: $classScanner,
            composerExtra: $composerExtra,
            schedule: $schedule
        )->discover();

        $this->assertSame(
            [
                'Switon\\Schedule\\Tests\\Fixtures\\GoodTask',
                'Switon\\Schedule\\Tests\\Fixtures\\SecondTask',
            ],
            $added
        );
    }

    public function testDiscoverFiltersInvalidComposerTasks(): void
    {
        $classScanner = $this->createMock(ClassScannerInterface::class);
        $classScanner->expects($this->once())
            ->method('scan')
            ->with(
                [
                    '@app/Task/*Task.php' => 'App\\Task\\*Task',
                    '@app/Areas/*/Task/*Task.php' => 'App\\Areas\\*\\Task\\*Task',
                ],
                Scheduled::class
            )
            ->willReturn([]);

        $composerExtra = $this->createMock(ComposerExtraInterface::class);
        $composerExtra->method('collect')
            ->with('switon.tasks')
            ->willReturn(['Switon\\Schedule\\Tests\\Fixtures\\GoodTask']);

        $schedule = $this->createMock(ScheduleInterface::class);
        $schedule->expects($this->once())
            ->method('register')
            ->with('Switon\\Schedule\\Tests\\Fixtures\\GoodTask');

        $this->makeDiscovery(
            classScanner: $classScanner,
            composerExtra: $composerExtra,
            schedule: $schedule
        )->discover();
    }

    public function testDiscoverStillScansWhenComposerExtraHasNoTasks(): void
    {
        $classScanner = $this->createMock(ClassScannerInterface::class);
        $classScanner->expects($this->once())
            ->method('scan')
            ->with(
                [
                    '@app/Task/*Task.php' => 'App\\Task\\*Task',
                    '@app/Areas/*/Task/*Task.php' => 'App\\Areas\\*\\Task\\*Task',
                ],
                Scheduled::class
            )
            ->willReturn([]);

        $composerExtra = $this->createMock(ComposerExtraInterface::class);
        $composerExtra->method('collect')->with('switon.tasks')->willReturn([]);

        $schedule = $this->createMock(ScheduleInterface::class);
        $schedule->expects($this->never())->method('register');

        $this->makeDiscovery(
            classScanner: $classScanner,
            composerExtra: $composerExtra,
            schedule: $schedule
        )->discover();
    }

    public function testAppScanWithDirectClassName(): void
    {
        $discovery = $this->makeDiscovery(['App\Task\MyTask']);

        $this->schedule->expects($this->once())
            ->method('register')
            ->with('App\Task\MyTask');

        $discovery->discover();
    }

    public function testAppScanWithGlobPattern(): void
    {
        $this->filesystem->expects($this->once())
            ->method('glob')
            ->with('@app/Task/*Task.php')
            ->willReturn(['/app/Task/UserTask.php']);

        $this->schedule->expects($this->once())
            ->method('register')
            ->with('App\Task\UserTask');

        $this->makeDiscovery(['@app/Task/*Task.php' => 'App\\Task\\*Task'])->discover();
    }

    public function testAppScanWithLegacyFormat(): void
    {
        $this->filesystem->expects($this->once())
            ->method('glob')
            ->with('@app/Areas/*/Task/*Task.php')
            ->willReturn(['/app/Areas/Admin/Task/AdminTask.php']);

        $this->schedule->expects($this->once())
            ->method('register')
            ->with('App\Areas\Admin\Task\AdminTask');

        $this->makeDiscovery([
            '@app/Areas/*/Task/*Task.php' => 'App\Areas\*\Task\*Task',
        ])->discover();
    }

    public function testAppScanWithMultipleTasks(): void
    {
        $callCount = 0;
        $this->filesystem->expects($this->exactly(2))
            ->method('glob')
            ->willReturnCallback(function ($pattern) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    $this->assertSame('@app/Task/*Task.php', $pattern);
                    return ['/app/Task/DemoTask.php'];
                }
                $this->assertSame('@app/Areas/*/Task/*Task.php', $pattern);
                return ['/app/Areas/Area1/Task/AreaTask.php'];
            });

        $addCalls = [];
        $this->schedule->expects($this->exactly(2))
            ->method('register')
            ->willReturnCallback(function (string $className) use (&$addCalls): void {
                $addCalls[] = $className;
            });

        $this->makeDiscovery([
            '@app/Task/*Task.php' => 'App\\Task\\*Task',
            '@app/Areas/*/Task/*Task.php' => 'App\\Areas\\*\\Task\\*Task',
        ])->discover();

        $this->assertSame(['App\Task\DemoTask', 'App\Areas\Area1\Task\AreaTask'], $addCalls);
    }

    public function testAppScanWithEmptyGlobResult(): void
    {
        $this->filesystem->expects($this->once())
            ->method('glob')
            ->with('@app/Task/*Task.php')
            ->willReturn([]);

        $this->schedule->expects($this->never())->method('register');

        $this->makeDiscovery(['@app/Task/*Task.php' => 'App\\Task\\*Task'])->discover();
    }

    public function testAppScanSkipsClassesWithoutScheduledAttribute(): void
    {
        $this->filesystem->expects($this->once())
            ->method('glob')
            ->with('@app/Task/*.php')
            ->willReturn([
                '/app/Task/AttributedScheduleTask.php',
                '/app/Task/UnmarkedTask.php',
            ]);

        $this->schedule->expects($this->once())
            ->method('register')
            ->with(AttributedScheduleTask::class);

        $this->makeDiscovery(['@app/Task/*.php' => 'App\\Task\\*'])->discover();
    }
}

class TestableTaskDiscovery extends TaskDiscovery
{
    /** @param array<string, string> $tasks */
    public function setTasks(array $tasks): void
    {
        $this->tasks = $tasks;
    }
}
