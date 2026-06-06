<?php

declare(strict_types=1);

namespace Switon\Schedule\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Switon\Cli\ServerInterface;
use Switon\Core\AppInterface;
use Switon\Core\ConsoleInterface;
use Switon\Schedule\Command\ScheduleCommand;
use Switon\Schedule\ScheduledTask;
use Switon\Schedule\ScheduleInterface;
use Switon\Schedule\TaskRunnerInterface;

#[AllowMockObjectsWithoutExpectations]
class ScheduleCommandTest extends TestCase
{
    protected ScheduleCommand $command;
    protected AppInterface&MockObject $app;
    protected TaskRunnerInterface&MockObject $runner;
    protected ConsoleInterface&MockObject $console;
    protected ScheduleInterface&MockObject $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new ScheduleCommandProbe();
        $this->app = $this->createMock(AppInterface::class);
        $this->runner = $this->createMock(TaskRunnerInterface::class);
        $this->console = $this->createMock(ConsoleInterface::class);
        $this->schedule = $this->createMock(ScheduleInterface::class);

        $this->command->setRunner($this->runner);
        $this->command->setConsole($this->console);
        $this->command->setApp($this->app);
        $this->command->setSchedule($this->schedule);
    }

    public function testRunActionReturnsSuccessWhenNoTaskFails(): void
    {
        $this->runner->expects($this->once())->method('runTasks');
        $this->runner->expects($this->once())->method('hadFailures')->willReturn(false);
        $this->console->expects($this->never())->method('error');

        $code = $this->command->runAction();

        $this->assertSame(0, $code);
    }

    public function testRunActionReturnsErrorWhenTaskFails(): void
    {
        $this->runner->expects($this->once())->method('runTasks');
        $this->runner->expects($this->once())->method('hadFailures')->willReturn(true);
        $this->console->expects($this->once())
            ->method('error')
            ->with(
                'One or more scheduled tasks failed.',
                [],
                ServerInterface::EXIT_GENERAL_ERROR
            )
            ->willReturn(ServerInterface::EXIT_GENERAL_ERROR);

        $code = $this->command->runAction();

        $this->assertSame(ServerInterface::EXIT_GENERAL_ERROR, $code);
    }

    public function testTaskActionReturnsUsageErrorWhenNameMissing(): void
    {
        $this->runner->expects($this->never())->method('runTaskNow');
        $this->runner->expects($this->never())->method('hadFailures');
        $this->console->expects($this->once())
            ->method('error')
            ->with(
                'Usage: schedule:task <name>',
                [],
                ServerInterface::EXIT_COMMAND_EXECUTION_FAILED
            )
            ->willReturn(ServerInterface::EXIT_COMMAND_EXECUTION_FAILED);

        $code = $this->command->taskAction('');

        $this->assertSame(ServerInterface::EXIT_COMMAND_EXECUTION_FAILED, $code);
    }

    public function testTaskActionReturnsErrorWhenTaskFails(): void
    {
        $this->runner->expects($this->once())->method('runTaskNow')->with('foo');
        $this->runner->expects($this->once())->method('hadFailures')->willReturn(true);
        $this->console->expects($this->once())
            ->method('error')
            ->with(
                'Scheduled task failed: {name}',
                ['name' => 'foo'],
                ServerInterface::EXIT_GENERAL_ERROR
            )
            ->willReturn(ServerInterface::EXIT_GENERAL_ERROR);

        $code = $this->command->taskAction('foo');

        $this->assertSame(ServerInterface::EXIT_GENERAL_ERROR, $code);
    }

    public function testTaskActionReturnsSuccessWhenTaskSucceeds(): void
    {
        $this->runner->expects($this->once())->method('runTaskNow')->with('foo');
        $this->runner->expects($this->once())->method('hadFailures')->willReturn(false);
        $this->console->expects($this->never())->method('error');

        $code = $this->command->taskAction('foo');

        $this->assertSame(0, $code);
    }

    public function testListActionWritesMessageWhenEmpty(): void
    {
        $this->schedule->expects($this->once())->method('all')->willReturn([]);
        $this->console->expects($this->once())
            ->method('writeLn')
            ->with('No scheduled tasks registered.');

        $code = $this->command->listAction();

        $this->assertSame(0, $code);
    }

    public function testListActionPrintsRegisteredTasks(): void
    {
        $this->app->expects($this->once())
            ->method('timezone')
            ->willReturn('Asia/Shanghai');

        $tasks = [
            'a' => new ScheduledTask('a', 'App\\Task\\X', '0 * * *', 0),
            'b' => new ScheduledTask('b', 'App\\Task\\Y', '0 0 * *', 60),
        ];
        $this->schedule->expects($this->once())->method('all')->willReturn($tasks);

        $lines = [];
        $this->console->expects($this->exactly(2))
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        $code = $this->command->listAction();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('a  lockTtl=0', $lines[0]);
        $this->assertStringContainsString('tz=Asia/Shanghai', $lines[0]);
        $this->assertStringContainsString('b  lockTtl=60', $lines[1]);
        $this->assertStringContainsString('tz=Asia/Shanghai', $lines[1]);
    }

    public function testListActionFallsBackToUtcWhenTimezoneBlank(): void
    {
        $this->app->expects($this->once())
            ->method('timezone')
            ->willReturn('   ');

        $this->schedule->expects($this->once())
            ->method('all')
            ->willReturn([
                'a' => new ScheduledTask('a', 'App\\Task\\X', '0 * * *', 0),
            ]);

        $lines = [];
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        $code = $this->command->listAction();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('tz=UTC', $lines[0]);
    }

    public function testDefaultActionDelegatesToList(): void
    {
        $this->schedule->expects($this->once())->method('all')->willReturn([]);
        $this->console->expects($this->once())
            ->method('writeLn')
            ->with('No scheduled tasks registered.');

        $code = $this->command->defaultAction();

        $this->assertSame(0, $code);
    }

}

class ScheduleCommandProbe extends ScheduleCommand
{
    public function setApp(AppInterface $app): void
    {
        $this->app = $app;
    }

    public function setRunner(TaskRunnerInterface $runner): void
    {
        $this->runner = $runner;
    }

    public function setConsole(ConsoleInterface $console): void
    {
        $this->console = $console;
    }

    public function setSchedule(ScheduleInterface $schedule): void
    {
        $this->schedule = $schedule;
    }
}
