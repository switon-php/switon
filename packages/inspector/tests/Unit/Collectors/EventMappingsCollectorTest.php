<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit\Collectors;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Switon\Eventing\EventLoggerInterface;
use Switon\Inspector\Collector\EventMappingsCollector;

#[AllowMockObjectsWithoutExpectations]
final class EventMappingsCollectorTest extends BaseTestCase
{
    public function testBootIsNoOp(): void
    {
        (new EventMappingsCollector())->boot();

        $this->assertTrue(true);
    }

    public function testCollectReturnsCategoryAndLevelMappings(): void
    {
        $collector = new EventMappingsCollectorTestable();
        $eventLogger = $this->createMock(EventLoggerInterface::class);
        $eventLogger->expects($this->once())
            ->method('getCategoryMapping')
            ->willReturn([
                'Switon\\Demo\\Event\\Created' => 'switon.demo.created',
            ]);
        $eventLogger->expects($this->once())
            ->method('getLevelMapping')
            ->willReturn([
                'Switon\\Demo\\Event\\Created' => 'info',
            ]);

        $collector->setEventLogger($eventLogger);

        $this->assertSame([
            'categories' => [
                'Switon\\Demo\\Event\\Created' => 'switon.demo.created',
            ],
            'levels' => [
                'Switon\\Demo\\Event\\Created' => 'info',
            ],
        ], $collector->collect());
    }
}

final class EventMappingsCollectorTestable extends EventMappingsCollector
{
    public function setEventLogger(EventLoggerInterface $eventLogger): void
    {
        $this->eventLogger = $eventLogger;
    }
}
