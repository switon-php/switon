<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Switon\Beacon\Command\ToolCommand;
use Switon\Beacon\Tests\Fixtures\Command\DummyToolCommand;
use Switon\Command\CommandDiscoveryInterface;
use Switon\Command\CommandInspectorInterface;
use Switon\Core\ConsoleInterface;
use Switon\Testing\Container;
use stdClass;

use function array_column;
use function json_decode;

#[AllowMockObjectsWithoutExpectations]
class ToolCommandTest extends TestCase
{
    protected ToolCommand $command;
    protected ConsoleInterface&MockObject $console;
    protected CommandDiscoveryInterface&MockObject $discovery;
    protected CommandInspectorInterface&MockObject $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->console = $this->createMock(ConsoleInterface::class);
        $this->discovery = $this->createMock(CommandDiscoveryInterface::class);
        $this->inspector = $this->createMock(CommandInspectorInterface::class);

        $this->command = $this->makeCommand();
    }

    public function testDescriptionActionReturnsErrorWhenInvocationMissing(): void
    {
        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->descriptionAction('');

        $this->assertSame(1, $code);
        $this->assertSame('invocation required', $captured['error']);
        $this->assertSame('tool:description <invocation>', $captured['usage']);
    }

    public function testDescriptionActionReturnsNotFoundForUnknownCommand(): void
    {
        $this->discovery->method('discover')->willReturn(['demo-command' => DummyToolCommand::class]);

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->descriptionAction('missing-command');

        $this->assertSame(1, $code);
        $this->assertSame('Command not found', $captured['error']);
        $this->assertSame('missing-command', $captured['command']);
    }

    public function testDescriptionActionFiltersSpecificActionFromInvocation(): void
    {
        $this->discovery->method('discover')->willReturn(['demo-command' => DummyToolCommand::class]);
        $this->inspector->method('getCommandDescription')->willReturn('Demo command');
        $this->inspector->method('getMethodDescription')
            ->willReturnCallback(static function (ReflectionMethod $method): string {
                return match ($method->getName()) {
                    'defaultAction' => 'Default action',
                    'runTaskAction' => 'Run task action',
                    default => '',
                };
            });
        $this->inspector->method('getOptions')
            ->willReturnCallback(static function (ReflectionMethod $method): array {
                return match ($method->getName()) {
                    'defaultAction' => [
                        'query' => ['type' => 'string', 'default' => '', 'description' => 'Search query'],
                    ],
                    'runTaskAction' => [
                        'limit' => ['type' => 'int', 'default' => 10, 'description' => 'Max items'],
                        'dryRun' => ['type' => 'bool', 'default' => false, 'description' => 'Dry run mode'],
                    ],
                    default => [],
                };
            });

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->descriptionAction('demo-command:run-task');

        $this->assertSame(0, $code);
        $this->assertSame('demo-command', $captured['command']);
        $this->assertCount(1, $captured['actions']);
        $this->assertSame('run-task', $captured['actions'][0]['name']);
        $this->assertSame('demo-command:run-task', $captured['actions'][0]['invocation']);
        $this->assertSame(10, $captured['actions'][0]['options'][0]['default']);
    }

    public function testDescriptionActionReturnsEmptyActionsForUnknownActionFilter(): void
    {
        $this->discovery->method('discover')->willReturn(['demo-command' => DummyToolCommand::class]);
        $this->inspector->method('getCommandDescription')->willReturn('Demo command');

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->descriptionAction('demo-command:not-exists');

        $this->assertSame(0, $code);
        $this->assertSame('demo-command', $captured['command']);
        $this->assertSame([], $captured['actions']);
    }

    public function testListActionIncludesOnlyEntriesWithAiDocAndSortsOutput(): void
    {
        $this->discovery->method('discover')->willReturn([
            'zeta' => DummyToolCommand::class,
            'alpha' => DummyToolCommand::class,
            'invalid' => ['not-a-class'],
        ]);

        $this->inspector->method('getActions')
            ->willReturn(['default' => 'default desc', 'runTask' => 'run desc']);
        $this->inspector->method('getActionAiDoc')
            ->willReturnCallback(static function (string $definition, string $action): ?string {
                if ($action === 'default') {
                    return 'Default action doc';
                }
                return $definition === DummyToolCommand::class ? 'Run task doc' : null;
            });

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->listAction();

        $this->assertSame(0, $code);
        $this->assertArrayHasKey('_conventions', $captured);
        $this->assertSame('Default action doc', $captured['alpha']);
        $this->assertSame('Run task doc', $captured['alpha:run-task']);
        $this->assertSame('Default action doc', $captured['zeta']);
        $this->assertSame('Run task doc', $captured['zeta:run-task']);
        $this->assertArrayNotHasKey('invalid', $captured);
    }

    public function testCommandsActionUsesDescriptionAndFallsBackToAiDoc(): void
    {
        $this->discovery->method('discover')->willReturn(['demo-command' => DummyToolCommand::class]);
        $this->inspector->method('getActions')->willReturn([
            'default' => 'Default from description',
            'runTask' => '',
        ]);
        $this->inspector->method('getActionAiDoc')
            ->willReturnCallback(static fn (string $definition, string $action): ?string => $action === 'runTask' ? 'Run from ai doc' : null);

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->commandsAction();

        $this->assertSame(0, $code);
        $this->assertSame('Default from description', $captured['demo-command']);
        $this->assertSame('Run from ai doc', $captured['demo-command:run-task']);
    }

    public function testEntryActionReturnsOneEntryWhenTopicProvided(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->entryAction('CACHE');

        $this->assertSame(0, $code);
        $this->assertCount(1, $captured['entries']);
        $this->assertSame('cache', $captured['entries'][0]['topic']);
        $this->assertStringContainsString('SimpleCache', $captured['entries'][0]['class']);
    }

    public function testEntryActionReturnsAllEntriesWithoutTopic(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->entryAction('');

        $this->assertSame(0, $code);
        $topics = array_column($captured['entries'], 'topic');
        $this->assertContains('cache', $topics);
        $this->assertContains('routing', $topics);
    }

    public function testJsonSafeDefaultDropsUnsupportedTypes(): void
    {
        $this->assertSame('x', $this->command->callJsonSafeDefault('x'));
        $this->assertNull($this->command->callJsonSafeDefault(new stdClass()));
        $this->assertSame(
            ['a' => 1, 'b' => null, 'd' => 'ok'],
            $this->command->callJsonSafeDefault(['a' => 1, 'b' => null, 'c' => new stdClass(), 'd' => 'ok'])
        );
    }

    private function makeCommand(): ToolCommand
    {
        $container = new Container([
            ConsoleInterface::class => $this->console,
            CommandDiscoveryInterface::class => $this->discovery,
            CommandInspectorInterface::class => $this->inspector,
        ]);

        return $container->make(ToolCommandHarness::class);
    }
}

class ToolCommandHarness extends ToolCommand
{
    public function callJsonSafeDefault(mixed $value): mixed
    {
        return $this->jsonSafeDefault($value);
    }
}
