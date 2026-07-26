<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Switon\Beacon\ClassIndexInterface;
use Switon\Beacon\Command\ClassCommand;
use Switon\Beacon\Exception\ClassIndexNotFoundException;
use Switon\Beacon\Tests\Fixtures\ClassCommand\DummyContract;
use Switon\Beacon\Tests\Fixtures\ClassCommand\DummyInspectable;
use Switon\Beacon\Tests\Fixtures\ClassCommand\DummyThrowable;
use Switon\Beacon\Tests\Fixtures\ClassCommand\Event\SampleEvent;
use Switon\Core\ConsoleInterface;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\FilesystemInterface;
use Switon\Core\FilterMatcherInterface;
use Switon\Testing\Container;
use DateTime;

use function json_decode;
use function str_contains;

#[AllowMockObjectsWithoutExpectations]
class ClassCommandTest extends TestCase
{
    protected ClassCommand $command;
    protected ClassIndexInterface&MockObject $classIndex;
    protected ConsoleInterface&MockObject $console;
    protected FilterMatcherInterface&MockObject $filterMatcher;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Fixtures/ClassCommand/Event/SampleEvent.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->classIndex = $this->createMock(ClassIndexInterface::class);
        $this->console = $this->createMock(ConsoleInterface::class);
        $this->filterMatcher = $this->createMock(FilterMatcherInterface::class);
        $container = new Container([
            ClassIndexInterface::class => $this->classIndex,
            ConsoleInterface::class => $this->console,
            FilterMatcherInterface::class => $this->filterMatcher,
        ]);

        $this->command = $container->make(ClassCommandHarness::class);
    }

    public function testListActionReturnsErrorWhenClassIndexUnavailable(): void
    {
        $this->classIndex->expects($this->once())
            ->method('all')
            ->willThrowException(ClassIndexNotFoundException::of('Class index unavailable. Run: composer dump-autoload -o'));

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->listAction('Dummy*');

        $this->assertSame(1, $code);
        $this->assertSame('class index unavailable', $captured['error']);
    }

    public function testListActionAppliesFilterMatcher(): void
    {
        $this->classIndex->method('all')->willReturn([DummyInspectable::class, DummyThrowable::class]);
        $this->filterMatcher->method('match')
            ->willReturnCallback(static fn (string $filter, string $value): bool => $value === DummyInspectable::class);

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->listAction('Dummy*');

        $this->assertSame(0, $code);
        $this->assertSame([DummyInspectable::class], $captured['classes']);
    }

    public function testInspectActionReturnsClassMetadataForExistingClass(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->inspectAction(DummyInspectable::class);

        $this->assertSame(0, $code);
        $this->assertSame(DummyInspectable::class, $captured['class']);
        $this->assertContains('work', $captured['methods']);
        $this->assertContains('count: int', $captured['properties']);
    }

    public function testContentActionReturnsMethodSourceWithDocBlockByDefault(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->contentAction(DummyInspectable::class . '::work');

        $this->assertSame(0, $code);
        $this->assertSame(DummyInspectable::class, $captured['class']);
        $this->assertSame('work', $captured['method']);
        $this->assertTrue(str_contains($captured['source'], 'Work method doc for source extraction tests.'));
        $this->assertTrue(str_contains($captured['source'], 'public function work'));
        $this->assertArrayHasKey('startLine', $captured);
        $this->assertArrayHasKey('endLine', $captured);
    }

    public function testMethodsActionInterfaceOnlyKeepsInterfacesAndNoSkipped(): void
    {
        $this->classIndex->method('all')->willReturn([
            DummyContract::class,
            DummyInspectable::class,
            DummyThrowable::class,
            SampleEvent::class,
            'Not\\Existing\\Type',
        ]);

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->methodsAction('', true);

        $this->assertSame(0, $code);
        $this->assertArrayHasKey(DummyContract::class, $captured['classes']);
        $this->assertArrayNotHasKey(DummyInspectable::class, $captured['classes']);
        $this->assertSame([], $captured['skipped']);
    }

    public function testMethodsActionDefaultModeSkipsEventThrowableAndMissingClass(): void
    {
        $this->classIndex->method('all')->willReturn([
            DummyContract::class,
            DummyInspectable::class,
            DummyThrowable::class,
            SampleEvent::class,
            'Not\\Existing\\Type',
        ]);

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->methodsAction();

        $this->assertSame(0, $code);
        $this->assertArrayHasKey(DummyContract::class, $captured['classes']);
        $this->assertArrayHasKey(DummyInspectable::class, $captured['classes']);
        $this->assertContains(DummyThrowable::class, $captured['skipped']);
        $this->assertContains(SampleEvent::class, $captured['skipped']);
        $this->assertContains('Not\\Existing\\Type', $captured['skipped']);
    }

    public function testListActionWithEmptyFilterRequestsMatchForEveryClass(): void
    {
        $this->classIndex->method('all')->willReturn([DummyInspectable::class, DummyContract::class]);

        $this->filterMatcher->method('match')
            ->willReturnCallback(static function (string $filter, string $_class): bool {
                self::assertSame('', $filter, 'Empty tool filter should probe every listing entry.');

                return true;
            });

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->listAction('');

        $this->assertSame(0, $code);
        $this->assertCount(2, $captured['classes']);
        $this->assertContains(DummyInspectable::class, $captured['classes']);
        $this->assertContains(DummyContract::class, $captured['classes']);
    }

    public function testMethodsActionReturnsErrorWhenClassIndexUnavailable(): void
    {
        $this->classIndex->expects($this->once())->method('all')
            ->willThrowException(ClassIndexNotFoundException::of('run dump'));

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->methodsAction('*');

        $this->assertSame(1, $code);
        $this->assertSame('class index unavailable', $captured['error']);
    }

    public function testMethodsActionWithFilterNarrowsViaMatchAnyForMethodNames(): void
    {
        $this->classIndex->method('all')->willReturn([DummyInspectable::class, DummyContract::class]);
        $this->filterMatcher->method('match')->willReturn(true);
        $this->filterMatcher->method('matchAny')->willReturnCallback(static function (string $filter, array $payload): bool {
            return isset($payload['method']) && $payload['method'] === 'work';
        });

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->methodsAction('*');

        $this->assertSame(0, $code);
        $this->assertArrayHasKey(DummyInspectable::class, $captured['classes']);
        $this->assertSame(['work'], $captured['classes'][DummyInspectable::class]);
        $this->assertArrayNotHasKey(DummyContract::class, $captured['classes']);
    }

    public function testPropertiesActionReturnsGroupedInstanceProperties(): void
    {
        $this->classIndex->method('all')->willReturn([DummyInspectable::class]);
        $this->filterMatcher->method('matchAny')->willReturn(true);

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->propertiesAction('');

        $this->assertSame(0, $code);
        $this->assertArrayHasKey(DummyInspectable::class, $captured['classes']);
        $names = preg_replace('/:.*/', '', $captured['classes'][DummyInspectable::class]);
        $this->assertContains('count', $names);
        $this->assertContains('name', $names);
    }

    public function testPropertiesActionReturnsErrorWhenClassIndexUnavailable(): void
    {
        $this->classIndex->method('all')
            ->willThrowException(ClassIndexNotFoundException::of('offline'));

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->propertiesAction('prop');

        $this->assertSame(1, $code);
        $this->assertSame('class index unavailable', $captured['error']);
    }

    public function testPropertiesActionFiltersPropertiesViaMatchAny(): void
    {
        $this->classIndex->method('all')->willReturn([DummyInspectable::class]);
        $this->filterMatcher->method('matchAny')->willReturnCallback(static function (string $filter, array $payload): bool {
            return ($payload['property'] ?? '') === 'count';
        });

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->propertiesAction('count');

        $this->assertSame(0, $code);
        $list = $captured['classes'][DummyInspectable::class];
        $this->assertCount(1, $list);
        $this->assertStringStartsWith('count', $list[0]);
    }

    public function testInspectActionReturnsNotFoundForUnknownClass(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->inspectAction('Ghost\\Ns\\Nothing');

        $this->assertSame(1, $code);
        $this->assertSame('class not found', $captured['error']);
    }

    public function testInspectActionReturnsMetadataForInterface(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->inspectAction(DummyContract::class);

        $this->assertSame(0, $code);
        $this->assertSame(DummyContract::class, $captured['class']);
        $this->assertContains('ping', $captured['methods']);
    }

    public function testContentActionReturnsErrorForMissingClass(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->contentAction('Missing\\Type');

        $this->assertSame(1, $code);
        $this->assertSame('class not found', $captured['error']);
    }

    public function testContentActionReturnsErrorForMissingMethod(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->contentAction(DummyInspectable::class . '::noSuchMethodBeacon');

        $this->assertSame(1, $code);
        $this->assertSame('method not found', $captured['error']);
    }

    public function testContentActionReturnsFullClassBodyWhenMethodOmitted(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->contentAction(DummyInspectable::class);

        $this->assertSame(0, $code);
        $this->assertArrayNotHasKey('method', $captured);
        $this->assertTrue(str_contains($captured['source'], 'class DummyInspectable'));
        $this->assertTrue(str_contains($captured['source'], 'function work'));
    }

    public function testContentActionOmitsPhpdocFromMethodSnippetWhenPhpdocDisabled(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->contentAction(DummyInspectable::class . '::work', false);

        $this->assertSame(0, $code);
        $this->assertFalse(str_contains($captured['source'], 'Work method doc for source extraction tests'));
    }

    public function testContentActionOmitsPhpdocFromClassBodyWhenDisabled(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->contentAction(DummyInspectable::class, false);

        $this->assertSame(0, $code);
        $this->assertFalse(str_contains($captured['source'], 'Work method doc for source extraction tests.'));
        $this->assertFalse(str_contains($captured['source'], '/**'));
    }

    public function testGetClassFileReturnsNullForUnknownOrInternalClass(): void
    {
        $this->assertSame(null, $this->command->exposedGetClassFile('Not\\Existing\\Type'));
        $this->assertSame(null, $this->command->exposedGetClassFile(DateTime::class));
        $this->assertNotNull($this->command->exposedGetClassFile(DummyInspectable::class));
    }

    public function testContentDataReportsUnreadableSource(): void
    {
        $filesystem = $this->createStub(FilesystemInterface::class);
        $filesystem->method('isFile')->willReturn(true);
        $filesystem->method('read')->willThrowException(FileNotFoundException::of('gone'));
        $container = new Container([
            ClassIndexInterface::class => $this->classIndex,
            ConsoleInterface::class => $this->console,
            FilterMatcherInterface::class => $this->filterMatcher,
            FilesystemInterface::class => $filesystem,
        ]);
        $command = $container->make(ClassCommandHarness::class);

        $result = $command->exposedGetContentData(DummyInspectable::class, null);

        $this->assertSame('could not read file', $result['error']['error']);
    }

}

class ClassCommandHarness extends ClassCommand
{
    public function exposedGetClassFile(string $fqcn): ?string
    {
        return $this->getClassFile($fqcn);
    }

    public function exposedGetContentData(string $class, ?string $method, bool $noPhpdoc = false): array
    {
        return $this->getContentData($class, $method, $noPhpdoc);
    }
}
