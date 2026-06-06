<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Switon\Beacon\ClassIndexInterface;
use Switon\Beacon\Command\PathCommand;
use Switon\Beacon\Exception\ClassIndexNotFoundException;
use Switon\Beacon\Tests\Fixtures\PathCommand\DummyPathTarget;
use Switon\Core\ConsoleInterface;
use Switon\Testing\Container;
use stdClass;

use function json_decode;

#[AllowMockObjectsWithoutExpectations]
class PathCommandTest extends TestCase
{
    protected PathCommand $command;
    protected ClassIndexInterface&MockObject $classIndex;
    protected ConsoleInterface&MockObject $console;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Fixtures/PathCommand/DummyPathTarget.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->classIndex = $this->createMock(ClassIndexInterface::class);
        $this->console = $this->createMock(ConsoleInterface::class);
        $this->command = $this->makeCommand();
    }

    public function testFqcnActionReturnsEmptyWhenPathSuffixIsEmpty(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->fqcnAction('   ');

        $this->assertSame(0, $code);
        $this->assertSame('', $captured['path']);
        $this->assertSame([], $captured['fqcns']);
    }

    public function testFqcnActionReturnsErrorWhenClassIndexUnavailable(): void
    {
        $this->classIndex->expects($this->once())
            ->method('all')
            ->willThrowException(ClassIndexNotFoundException::of('Class index unavailable'));

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->fqcnAction('tests/Fixtures/PathCommand/DummyPathTarget.php');

        $this->assertSame(1, $code);
        $this->assertSame('class index unavailable', $captured['error']);
    }

    public function testFqcnActionMatchesByPathAndPhpBasename(): void
    {
        $this->classIndex->method('all')->willReturn([
            DummyPathTarget::class,
            stdClass::class,
        ]);

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->fqcnAction('PathCommand/DummyPathTarget.php');

        $this->assertSame(0, $code);
        $this->assertSame(['path' => 'PathCommand/DummyPathTarget.php', 'fqcns' => [DummyPathTarget::class]], $captured);
    }

    public function testFqcnActionWithBackslashPhpPathReturnsEmpty(): void
    {
        $this->classIndex->method('all')->willReturn([DummyPathTarget::class]);

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->fqcnAction('PathCommand\\DummyPathTarget.php');

        $this->assertSame(0, $code);
        $this->assertSame([], $captured['fqcns']);
    }

    private function makeCommand(): PathCommand
    {
        $container = new Container([
            ClassIndexInterface::class => $this->classIndex,
            ConsoleInterface::class => $this->console,
        ]);

        return $container->make(PathCommand::class);
    }
}
