<?php

declare(strict_types=1);

namespace Switon\Schedule\Tests\Unit;

use App\Billing\Areas\SyncTask as BillingAreasSyncTask;
use App\Task\Collision\DupTask as TaskCollisionDup;
use App\Task\SyncTask as TaskNamespaceSyncTask;
use App\Tasks\Bill\SyncTask as TasksBillSyncTask;
use App\Tasks\Collision\DupTask as TasksCollisionDup;
use Switon\Core\Exception\MisuseException;
use Switon\Schedule\Schedule;
use Switon\Schedule\Tests\Fixtures\BadExpressionFieldTask;
use Switon\Schedule\Tests\Fixtures\CustomLockTtlTask;
use Switon\Schedule\Tests\Fixtures\FiveFieldTask;
use Switon\Schedule\Tests\Fixtures\FooBarTask;
use Switon\Schedule\Tests\Fixtures\GoodTask;
use Switon\Schedule\Tests\Fixtures\InvalidLockTtlNegativeTask;
use Switon\Schedule\Tests\Fixtures\InvalidLockTtlTooHighTask;
use Switon\Schedule\Tests\Fixtures\NoAttributeTask;
use Switon\Schedule\Tests\Fixtures\ScheduledWithoutRunnerTask;
use Switon\Schedule\Tests\Fixtures\SecondTask;
use Switon\Schedule\Tests\Fixtures\StepZeroTask;
use Switon\Schedule\Tests\TestCase;

class ScheduleRegistrationTest extends TestCase
{
    protected function createSchedule(): Schedule
    {
        return $this->make(Schedule::class);
    }

    public function testRegisterAcceptsTaskClassWithScheduledAttribute(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(GoodTask::class);

        $task = $schedule->get('schedule.tests.fixtures.good');
        $this->assertNotNull($task);
        $this->assertSame(GoodTask::class, $task->taskClass);
        $this->assertSame(0, $task->lockTtl);
    }

    public function testRegisterRejectsMissingAttribute(): void
    {
        $this->expectException(MisuseException::class);

        $schedule = $this->createSchedule();
        $schedule->register(NoAttributeTask::class);
    }

    public function testRegisterRejectsScheduledClassWithoutRunnerInterface(): void
    {
        $this->expectException(MisuseException::class);

        $schedule = $this->createSchedule();
        $schedule->register(ScheduledWithoutRunnerTask::class);
    }

    public function testRegisterRejectsFiveFieldExpression(): void
    {
        $this->expectException(MisuseException::class);

        $schedule = $this->createSchedule();
        $schedule->register(FiveFieldTask::class);
    }

    public function testRegisterRejectsDuplicateClass(): void
    {
        $this->expectException(MisuseException::class);

        $schedule = $this->createSchedule();
        $schedule->register(GoodTask::class);
        $schedule->register(GoodTask::class);
    }

    public function testNameDerivedAsTaskClassToId(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(FooBarTask::class);
        $task = $schedule->get('schedule.tests.fixtures.foo-bar');
        $this->assertNotNull($task);
        $this->assertSame(FooBarTask::class, $task->taskClass);
        $this->assertSame(0, $task->lockTtl);
    }

    public function testRegisterAcceptsCustomLockTtl(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(CustomLockTtlTask::class);
        $task = $schedule->get('schedule.tests.fixtures.custom-lock-ttl');
        $this->assertNotNull($task);
        $this->assertSame(120, $task->lockTtl);
    }

    public function testRegisterRejectsNegativeLockTtl(): void
    {
        $this->expectException(MisuseException::class);

        $schedule = $this->createSchedule();
        $schedule->register(InvalidLockTtlNegativeTask::class);
    }

    public function testFourFieldExpressionRegisters(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(SecondTask::class);
        $task = $schedule->get('schedule.tests.fixtures.second');
        $this->assertNotNull($task);
    }

    public function testRegisterStripsLeadingTaskSegmentFromTaskId(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(TaskNamespaceSyncTask::class);

        $task = $schedule->get('sync');
        $this->assertNotNull($task);
        $this->assertSame(TaskNamespaceSyncTask::class, $task->taskClass);
    }

    public function testRegisterKeepsLeadingTasksSegmentInTaskId(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(TasksBillSyncTask::class);

        $task = $schedule->get('tasks.bill.sync');
        $this->assertNotNull($task);
        $this->assertSame(TasksBillSyncTask::class, $task->taskClass);
    }

    public function testRegisterRemovesAreasOnlyWhenSecondSegment(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(BillingAreasSyncTask::class);

        $task = $schedule->get('billing.sync');
        $this->assertNotNull($task);
        $this->assertSame(BillingAreasSyncTask::class, $task->taskClass);
    }

    public function testRegisterRejectsUnknownClass(): void
    {
        $this->expectException(MisuseException::class);

        $schedule = $this->createSchedule();
        $schedule->register('Switon\\Schedule\\Tests\\Fixtures\\DoesNotExistTask');
    }

    public function testRegisterRejectsTaskClassThatCannotDeriveAnId(): void
    {
        $this->expectException(MisuseException::class);

        $schedule = $this->createSchedule();
        $schedule->register(\App\Task\Task::class);
    }

    public function testRegisterRejectsLockTtlTooHigh(): void
    {
        $this->expectException(MisuseException::class);

        $schedule = $this->createSchedule();
        $schedule->register(InvalidLockTtlTooHighTask::class);
    }

    public function testRegisterRejectsInvalidExpressionField(): void
    {
        $this->expectException(MisuseException::class);

        $schedule = $this->createSchedule();
        $schedule->register(BadExpressionFieldTask::class);
    }

    public function testRegisterRejectsStepZeroExpressionField(): void
    {
        $this->expectException(MisuseException::class);
        $this->expectExceptionMessage('*/0');

        $schedule = $this->createSchedule();
        $schedule->register(StepZeroTask::class);
    }

    public function testRegisterDoesNotCollideWhenOnlyTasksSegmentDiffers(): void
    {
        $schedule = $this->createSchedule();
        $schedule->register(TaskCollisionDup::class);
        $schedule->register(TasksCollisionDup::class);

        $task = $schedule->get('tasks.collision.dup');
        $this->assertNotNull($task);
        $this->assertSame(TasksCollisionDup::class, $task->taskClass);
    }
}
