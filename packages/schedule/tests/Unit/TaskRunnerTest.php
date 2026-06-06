<?php

declare(strict_types=1);

namespace Switon\Schedule\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\AppInterface;
use Switon\Core\ContainerInterface;
use Switon\Core\Exception\MisuseException;
use Switon\Core\RunnerInterface;
use Switon\Core\Runtime;
use Switon\Executor\ExecutorInterface;
use Switon\Executor\FutureInterface;
use Switon\Schedule\Event\TaskFailed;
use Switon\Schedule\Event\TaskFinished;
use Switon\Schedule\Event\TaskStarted;
use Switon\Schedule\Expression;
use Switon\Schedule\LockInterface;
use Switon\Schedule\Schedule;
use Switon\Schedule\TaskRunner;
use Switon\Schedule\Tests\Fixtures\CustomLockTtlTask;
use Switon\Schedule\Tests\Fixtures\GoodTask;
use Switon\Testing\MockClock;
use Switon\Schedule\Tests\TestCase;
use RuntimeException;
use Throwable;

#[AllowMockObjectsWithoutExpectations]
class TaskRunnerTest extends TestCase
{
    protected function createSchedule(): Schedule
    {
        return $this->make(Schedule::class);
    }

    public function testRunTasksInvokesWhenExpressionMatches(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(GoodTask::class);

        $this->container->set(GoodTask::class, new GoodTask());

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->atLeastOnce())->method('dispatch');

        $lock = $this->createMock(LockInterface::class);
        $runner = $this->makeRunner(
            $schedule,
            new MockClock((float)strtotime('2024-06-15 10:20:00 UTC')),
            $events,
            $this->makeApp(),
            $lock
        );

        $runner->runTasks();
        $this->assertFalse($runner->hadFailures());
    }

    public function testRunTaskNowUsesExecutorInvoke(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(GoodTask::class);

        $this->container->set(GoodTask::class, new GoodTask());

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->atLeastOnce())->method('dispatch');

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->once())
            ->method('invoke')
            ->with(GoodTask::class, null);

        $runner = $this->makeRunner(
            $schedule,
            new MockClock((float)strtotime('2024-06-15 10:20:37 UTC')),
            $events,
            $this->makeApp(),
            $this->createMock(LockInterface::class),
            $executor
        );

        $runner->runTaskNow('schedule.tests.fixtures.good');
        $this->assertFalse($runner->hadFailures());
    }

    public function testRunTaskNowSkipsExpressionMatch(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(GoodTask::class);

        $this->container->set(GoodTask::class, new GoodTask());

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->atLeastOnce())->method('dispatch');

        $runner = $this->makeRunner(
            $schedule,
            new MockClock((float)strtotime('2024-06-15 10:20:37 UTC')),
            $events,
            $this->makeApp(),
            $this->createMock(LockInterface::class)
        );

        $runner->runTaskNow('schedule.tests.fixtures.good');
        $this->assertFalse($runner->hadFailures());
    }

    public function testRunTaskNowMarksFailureWhenTaskThrows(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(GoodTask::class);

        $this->container->set(
            GoodTask::class,
            new class () implements RunnerInterface {
                public function run(mixed $payload): mixed
                {
                    throw new RuntimeException('task failed');
                }
            }
        );

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->atLeastOnce())->method('dispatch');

        $runner = $this->makeRunner(
            $schedule,
            new MockClock((float)strtotime('2024-06-15 10:20:37 UTC')),
            $events,
            $this->makeApp(),
            $this->createMock(LockInterface::class)
        );

        $runner->runTaskNow('schedule.tests.fixtures.good');
        $this->assertTrue($runner->hadFailures());
    }

    public function testRunTasksDoesNotInvokeWhenExpressionDoesNotMatch(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(GoodTask::class);

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->never())->method('dispatch');

        $lock = $this->createMock(LockInterface::class);
        $lock->expects($this->never())->method('tryAcquire');

        $runner = $this->makeRunner(
            $schedule,
            new MockClock((float)strtotime('2024-06-15 10:20:37 UTC')),
            $events,
            $this->makeApp(''),
            $lock
        );

        $runner->runTasks();
        $this->assertFalse($runner->hadFailures());
    }

    #[RequiresPhpExtension('swoole')]
    public function testRunTasksUsesCoroutineBatchWhenEnabled(): void
    {
        \Swoole\Coroutine\run(function (): void {
            Runtime::setCoroutineEnabled(true);
            try {
                $schedule = $this->createSchedule();
                $schedule->register(GoodTask::class);

                $this->container->set(GoodTask::class, new GoodTask());

                $events = $this->createMock(EventDispatcherInterface::class);
                $events->expects($this->atLeastOnce())->method('dispatch');

                $runner = $this->makeRunner(
                    $schedule,
                    new MockClock((float)strtotime('2024-06-15 10:20:00 UTC')),
                    $events,
                    $this->makeApp(),
                    $this->createMock(LockInterface::class)
                );

                $runner->runTasks();

                $this->assertFalse($runner->hadFailures());
            } finally {
                Runtime::setCoroutineEnabled(false);
            }
        });
    }

    public function testRunTasksClusterSkipsWhenLockNotAcquired(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(CustomLockTtlTask::class);

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->never())->method('dispatch');

        $lock = $this->createMock(LockInterface::class);
        $lock->expects($this->once())
            ->method('tryAcquire')
            ->willReturn(false);
        $lock->expects($this->never())->method('release');

        $runner = $this->makeRunner(
            $schedule,
            new MockClock((float)strtotime('2024-06-15 10:20:00 UTC')),
            $events,
            $this->makeApp(),
            $lock
        );

        $runner->runTasks();
        $this->assertFalse($runner->hadFailures());
    }

    public function testRunTasksClusterAcquiresAndReleasesLock(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(CustomLockTtlTask::class);

        $this->container->set(CustomLockTtlTask::class, new CustomLockTtlTask());

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->atLeastOnce())->method('dispatch');

        $expectedKey = 'schedule:test-app:schedule.tests.fixtures.custom-lock-ttl';
        $lock = $this->createMock(LockInterface::class);
        $lock->expects($this->once())
            ->method('tryAcquire')
            ->with($expectedKey, 120)
            ->willReturn(true);
        $lock->expects($this->once())->method('release')->with($expectedKey);

        $runner = $this->makeRunner(
            $schedule,
            new MockClock((float)strtotime('2024-06-15 10:20:00 UTC')),
            $events,
            $this->makeApp(),
            $lock
        );

        $runner->runTasks();
        $this->assertFalse($runner->hadFailures());
    }

    public function testRunTasksWithoutLockTtlDoesNotUseDistributedLock(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(GoodTask::class);

        $this->container->set(GoodTask::class, new GoodTask());

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->atLeastOnce())->method('dispatch');

        $lock = $this->createMock(LockInterface::class);
        $lock->expects($this->never())->method('tryAcquire');
        $lock->expects($this->never())->method('release');

        $runner = $this->makeRunner(
            $schedule,
            new MockClock((float)strtotime('2024-06-15 10:20:00 UTC')),
            $events,
            $this->makeApp(),
            $lock
        );

        $runner->runTasks();
        $this->assertFalse($runner->hadFailures());
    }

    public function testRunTaskNowThrowsWhenTaskNameUnknown(): void
    {
        $this->expectException(MisuseException::class);

        $runner = $this->makeRunner(
            $this->createSchedule(),
            new MockClock((float)strtotime('2024-06-15 10:20:00 UTC')),
            $this->createMock(EventDispatcherInterface::class),
            $this->makeApp(''),
            $this->createMock(LockInterface::class)
        );

        $runner->runTaskNow('does-not-exist');
    }

    public function testRunTasksReleasesLockWhenTaskStartedListenerThrows(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(CustomLockTtlTask::class);

        $dispatched = [];
        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): void {
                $dispatched[] = $event;
                if ($event instanceof TaskStarted) {
                    throw new RuntimeException('listener failed');
                }
            });

        $expectedKey = 'schedule:test-app:schedule.tests.fixtures.custom-lock-ttl';
        $lock = $this->createMock(LockInterface::class);
        $lock->expects($this->once())
            ->method('tryAcquire')
            ->with($expectedKey, 120)
            ->willReturn(true);
        $lock->expects($this->once())->method('release')->with($expectedKey);

        $runner = $this->makeRunner(
            $schedule,
            new MockClock((float)strtotime('2024-06-15 10:20:00 UTC')),
            $events,
            $this->makeApp(),
            $lock
        );

        $runner->runTasks();

        $this->assertTrue($runner->hadFailures());
        $this->assertInstanceOf(TaskStarted::class, $dispatched[0]);
        $this->assertInstanceOf(TaskFailed::class, $dispatched[1]);
    }

    public function testRunTasksReleasesLockWhenTaskExecutionThrows(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(CustomLockTtlTask::class);

        $this->container->set(
            CustomLockTtlTask::class,
            new class () implements RunnerInterface {
                public function run(mixed $payload): mixed
                {
                    throw new RuntimeException('task execution failed');
                }
            }
        );

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->atLeastOnce())->method('dispatch');

        $expectedKey = 'schedule:test-app:schedule.tests.fixtures.custom-lock-ttl';
        $lock = $this->createMock(LockInterface::class);
        $lock->expects($this->once())
            ->method('tryAcquire')
            ->with($expectedKey, 120)
            ->willReturn(true);
        $lock->expects($this->once())->method('release')->with($expectedKey);

        $runner = $this->makeRunner(
            $schedule,
            new MockClock((float)strtotime('2024-06-15 10:20:00 UTC')),
            $events,
            $this->makeApp(),
            $lock
        );

        $runner->runTasks();

        $this->assertTrue($runner->hadFailures());
    }

    public function testRunTaskNowDispatchesStartedFinishedInOrder(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(GoodTask::class);

        $this->container->set(GoodTask::class, new GoodTask());

        $dispatched = [];
        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): void {
                $dispatched[] = $event;
            });

        $runner = $this->makeRunner(
            $schedule,
            new MockClock((float)strtotime('2024-06-15 10:20:37 UTC')),
            $events,
            $this->makeApp(),
            $this->createMock(LockInterface::class)
        );

        $runner->runTaskNow('schedule.tests.fixtures.good');

        $this->assertInstanceOf(TaskStarted::class, $dispatched[0]);
        $this->assertInstanceOf(TaskFinished::class, $dispatched[1]);
        $this->assertSame('schedule.tests.fixtures.good', $dispatched[0]->name);
    }

    public function testRunTaskNowDispatchesTaskFailedAfterStartedWhenTaskThrows(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(GoodTask::class);

        $this->container->set(
            GoodTask::class,
            new class () implements RunnerInterface {
                public function run(mixed $payload): mixed
                {
                    throw new RuntimeException('boom');
                }
            }
        );

        $dispatched = [];
        $events = $this->createMock(EventDispatcherInterface::class);
        $events->method('dispatch')->willReturnCallback(static function (object $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $runner = $this->makeRunner(
            $schedule,
            new MockClock((float)strtotime('2024-06-15 10:20:37 UTC')),
            $events,
            $this->makeApp(),
            $this->createMock(LockInterface::class)
        );

        $runner->runTaskNow('schedule.tests.fixtures.good');

        $this->assertInstanceOf(TaskStarted::class, $dispatched[0]);
        $this->assertInstanceOf(TaskFailed::class, $dispatched[1]);
        $this->assertSame('schedule.tests.fixtures.good', $dispatched[1]->name);
    }

    protected function makeRunner(
        Schedule                 $schedule,
        MockClock                $clock,
        EventDispatcherInterface $events,
        AppInterface             $app,
        LockInterface            $lock,
        ?ExecutorInterface       $executor = null
    ): TaskRunner {
        return $this->make(TaskRunner::class, [
            'schedule' => $schedule,
            'expression' => new Expression(),
            'clock' => $clock,
            'executor' => $executor ?? new FakeExecutor($this->container),
            'eventDispatcher' => $events,
            'app' => $app,
            'lock' => $lock,
        ]);
    }

    protected function makeApp(string $timezone = 'UTC'): AppInterface
    {
        $app = $this->createMock(AppInterface::class);
        $app->method('id')->willReturn('test-app');
        $app->method('timezone')->willReturn($timezone);

        return $app;
    }
}

class FakeExecutor implements ExecutorInterface
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    public function invoke(string $task, mixed $payload = null): mixed
    {
        return $this->container->get($task)->run($payload);
    }

    public function submit(string $task, mixed $payload = null, int $concurrency = 1): void
    {
        $this->container->get($task)->run($payload);
    }

    public function invokeAll(array $tasks): array
    {
        $futures = [];

        foreach ($tasks as $taskDefinition) {
            try {
                $futures[] = FakeFuture::success(
                    $this->container->get($taskDefinition[0])->run($taskDefinition[1] ?? null)
                );
            } catch (Throwable $error) {
                $futures[] = FakeFuture::failure($error);
            }
        }

        return $futures;
    }

    public function invokeAny(array $tasks): mixed
    {
        foreach ($this->invokeAll($tasks) as $future) {
            if ($future->isSuccessful()) {
                return $future->result();
            }
        }

        return null;
    }
}

class FakeFuture implements FutureInterface
{
    protected function __construct(
        protected bool        $successful,
        protected mixed       $result = null,
        protected ?Throwable $error = null,
    ) {
    }

    public static function success(mixed $result = null): self
    {
        return new self(true, $result);
    }

    public static function failure(Throwable $error): self
    {
        return new self(false, null, $error);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    public function error(): ?Throwable
    {
        return $this->error;
    }
}
