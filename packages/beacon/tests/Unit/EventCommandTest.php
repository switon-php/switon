<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Switon\Beacon\Command\EventCommand;
use Switon\Beacon\EventScannerInterface;
use Switon\Beacon\Tests\Fixtures\Event\RealEvent;
use Switon\Beacon\Tests\Fixtures\Event\Sub\PlainEvent;
use Switon\Beacon\Tests\Fixtures\Event\Sub\WithInterface;
use Switon\Beacon\Tests\Fixtures\EventCommand\EventPayloadEvent;
use Switon\Core\ConsoleInterface;
use Switon\Core\FilterMatcherInterface;
use Switon\Eventing\EventLoggerInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Testing\Container;

use function json_decode;

#[AllowMockObjectsWithoutExpectations]
class EventCommandTest extends TestCase
{
    protected EventCommand $command;
    protected ConsoleInterface&MockObject $console;
    protected EventLoggerInterface&MockObject $eventLogger;
    protected EventScannerInterface&MockObject $eventScanner;
    protected FilterMatcherInterface&MockObject $filterMatcher;
    protected ListenerProviderInterface&MockObject $listenerProvider;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Fixtures/Event/RealEvent.php';
        require_once dirname(__DIR__) . '/Fixtures/Event/Sub/PlainEvent.php';
        require_once dirname(__DIR__) . '/Fixtures/Event/Sub/SomeInterface.php';
        require_once dirname(__DIR__) . '/Fixtures/Event/Sub/WithInterface.php';
        require_once dirname(__DIR__) . '/Fixtures/Event/Sub/WithBuiltinContractEvent.php';
        require_once dirname(__DIR__) . '/Fixtures/EventCommand/EventPayloadEvent.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->console = $this->createMock(ConsoleInterface::class);
        $this->eventLogger = $this->createMock(EventLoggerInterface::class);
        $this->eventScanner = $this->createMock(EventScannerInterface::class);
        $this->filterMatcher = $this->createMock(FilterMatcherInterface::class);
        $this->listenerProvider = $this->createMock(ListenerProviderInterface::class);
        $container = new Container([
            ConsoleInterface::class => $this->console,
            EventLoggerInterface::class => $this->eventLogger,
            EventScannerInterface::class => $this->eventScanner,
            FilterMatcherInterface::class => $this->filterMatcher,
            ListenerProviderInterface::class => $this->listenerProvider,
        ]);

        $this->command = $container->make(EventCommand::class);
    }

    public function testClassesActionReturnsFilteredEventsInJson(): void
    {
        $this->eventScanner->method('scanEventClasses')->willReturn([
            PlainEvent::class,
            WithInterface::class,
            RealEvent::class,
        ]);
        $this->eventLogger->method('categoryForClass')
            ->willReturnCallback(static fn (string $class): string => 'cat:' . basename(str_replace('\\', '/', $class)));
        $this->filterMatcher->method('matchAny')
            ->willReturnCallback(static fn (string $filter, array $payload): bool => $payload['class'] !== WithInterface::class);

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->classesAction('keep', true);

        $this->assertSame(0, $code);
        $this->assertCount(2, $captured['events']);
        $this->assertSame(PlainEvent::class, $captured['events'][0]['class']);
        $this->assertSame('cat:PlainEvent', $captured['events'][0]['category']);
    }

    public function testClassesActionWritesPlainTextWhenJsonDisabled(): void
    {
        $this->eventScanner->method('scanEventClasses')->willReturn([PlainEvent::class]);
        $this->eventLogger->method('categoryForClass')->willReturn('demo.code');
        $this->filterMatcher->method('matchAny')->willReturn(true);

        $this->console->expects($this->once())
            ->method('writeLn')
            ->with($this->stringContains(PlainEvent::class . '  demo.code'));

        $this->assertSame(0, $this->command->classesAction('', false));
    }

    public function testCategoryActionReturnsJsonAndPlainText(): void
    {
        $this->eventLogger->method('categoryForClass')->willReturn('switon.event.real');

        $capturedJson = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$capturedJson): void {
                $capturedJson = json_decode($line, true);
            });

        $this->assertSame(0, $this->command->categoryAction(RealEvent::class, true));
        $this->assertSame(RealEvent::class, $capturedJson['events'][0]['class']);
        $this->assertSame('switon.event.real', $capturedJson['events'][0]['category']);
    }

    public function testCategoryActionWritesPlainTextWhenJsonDisabled(): void
    {
        $this->eventLogger->method('categoryForClass')->willReturn('switon.event.real');

        $this->console->expects($this->once())
            ->method('writeLn')
            ->with(RealEvent::class . '  switon.event.real');

        $this->assertSame(0, $this->command->categoryAction(RealEvent::class, false));
    }

    public function testByCodeActionFiltersAndWritesPlainText(): void
    {
        $this->eventScanner->method('scanEventClasses')->willReturn([
            PlainEvent::class,
            RealEvent::class,
        ]);
        $this->eventLogger->method('categoryForClass')
            ->willReturnCallback(static fn (string $class): string => $class === PlainEvent::class ? 'alpha' : 'beta');
        $this->filterMatcher->method('matchAny')
            ->willReturnCallback(static fn (string $filter, array $payload): bool => $payload['category'] === 'alpha');

        $lines = [];
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        $this->assertSame(0, $this->command->byCodeAction('alpha', false));
        $this->assertSame([PlainEvent::class . '  alpha'], $lines);
    }

    public function testClassActionReturnsPropertiesAndPlainTextFormatting(): void
    {
        $this->eventScanner->method('scanEventClasses')->willReturn([EventPayloadEvent::class]);
        $this->eventLogger->method('categoryForClass')->willReturn('payload');

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $this->assertSame(0, $this->command->classAction(EventPayloadEvent::class, true));
        $this->assertSame(EventPayloadEvent::class, $captured['class']);
        $this->assertSame('payload', $captured['category']);
        $this->assertSame('id', $captured['properties'][0]['name']);
    }

    public function testClassActionWritesPlainTextWhenJsonDisabled(): void
    {
        $this->eventScanner->method('scanEventClasses')->willReturn([EventPayloadEvent::class]);
        $this->eventLogger->method('categoryForClass')->willReturn('payload');

        $this->console->expects($this->once())
            ->method('writeLn')
            ->with($this->stringContains(EventPayloadEvent::class . '  payload'));

        $this->assertSame(0, $this->command->classAction(EventPayloadEvent::class, false));
    }

    public function testClassActionReturnsErrorForMissingClass(): void
    {
        $this->eventScanner->method('scanEventClasses')->willReturn([]);
        $this->console->expects($this->once())
            ->method('error')
            ->with(
                'Event class not found: {class}',
                ['class' => 'Missing\\Event'],
                1
            )
            ->willReturn(1);

        $this->assertSame(1, $this->command->classAction('Missing\\Event', true));
    }

    public function testListenersActionFiltersAndOutputsJsonAndPlainText(): void
    {
        $this->listenerProvider->method('getListeners')->willReturn([
            PlainEvent::class => [
                PlainEvent::class . '::handle',
                WithInterface::class . '::skip',
            ],
            RealEvent::class => [
                RealEvent::class . '::onReal',
            ],
        ]);
        $this->filterMatcher->method('matchAny')
            ->willReturnCallback(static fn (string $filter, array $payload): bool => ($payload['event'] ?? '') === PlainEvent::class && ($payload['listener'] ?? '') === PlainEvent::class . '::handle');

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $this->assertSame(0, $this->command->listenersAction('plain', true));
        $this->assertSame([PlainEvent::class => [PlainEvent::class . '::handle']], $captured['events']);
    }

    public function testListenersActionWritesPlainTextWhenJsonDisabled(): void
    {
        $this->listenerProvider->method('getListeners')->willReturn([
            PlainEvent::class => [PlainEvent::class . '::handle'],
        ]);
        $this->filterMatcher->method('matchAny')->willReturn(true);

        $lines = [];
        $this->console->expects($this->exactly(2))
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        $this->assertSame(0, $this->command->listenersAction('', false));
        $this->assertSame(
            [
                PlainEvent::class . ':',
                '  ' . PlainEvent::class . '::handle',
            ],
            $lines
        );
    }

    public function testGetPublicPropertiesSkipsStaticAndPreservesTypes(): void
    {
        $this->eventScanner->method('scanEventClasses')->willReturn([EventPayloadEvent::class]);
        $this->eventLogger->method('categoryForClass')->willReturn('payload');

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $this->assertSame(0, $this->command->classAction(EventPayloadEvent::class, true));
        $this->assertSame([
            ['name' => 'id', 'type' => 'string'],
            ['name' => 'count', 'type' => 'int'],
        ], $captured['properties']);
    }

}
