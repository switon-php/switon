<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Switon\Beacon\ClassIndexInterface;
use Switon\Beacon\EventScanner;
use Switon\Beacon\Tests\Fixtures\Event\RealEvent;
use Switon\Beacon\Tests\Fixtures\Event\Sub\PlainEvent;
use Switon\Beacon\Tests\Fixtures\Event\Sub\SomeInterface;
use Switon\Beacon\Tests\Fixtures\Event\Sub\WithBuiltinContractEvent;
use Switon\Beacon\Tests\Fixtures\Event\Sub\WithInterface;
use Switon\Beacon\Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
class EventScannerTest extends TestCase
{
    protected ClassIndexInterface&MockObject $classIndex;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();
        $this->classIndex = $this->createMock(ClassIndexInterface::class);
        $this->container->set(ClassIndexInterface::class, $this->classIndex);
    }

    public function testScanEventClassesExcludesInterfaceTypeAndClassesImplementingInterface(): void
    {
        $all = [
            PlainEvent::class,
            WithInterface::class,
            SomeInterface::class,
        ];
        $this->classIndex->method('all')->willReturn($all);

        $scanner = $this->container->make(EventScanner::class);
        $result = $scanner->scanEventClasses();

        $this->assertSame([PlainEvent::class], $result);
    }

    public function testScanEventClassesExcludesFqcnEndingWithInterface(): void
    {
        $all = [
            'App\Event\SomeHandlerInterface',
        ];
        $this->classIndex->method('all')->willReturn($all);

        $scanner = $this->container->make(EventScanner::class);
        $result = $scanner->scanEventClasses();

        $this->assertSame([], $result);
    }

    public function testScanEventClassesKeepsClassInEventsNamespaceWithNoInterfaces(): void
    {
        $all = [RealEvent::class];
        $this->classIndex->method('all')->willReturn($all);

        $scanner = $this->container->make(EventScanner::class);
        $result = $scanner->scanEventClasses();

        $this->assertSame([RealEvent::class], $result);
    }

    public function testScanEventClassesSkipsNonEventNamespaceBeforeClassInspection(): void
    {
        $all = [
            'App\\Service\\PseudoEvent',
            PlainEvent::class,
        ];
        $this->classIndex->method('all')->willReturn($all);

        $scanner = $this->container->make(EventScanner::class);
        $result = $scanner->scanEventClasses();

        $this->assertSame([PlainEvent::class], $result);
    }

    public function testScanEventClassesKeepsEventOrderAfterFiltering(): void
    {
        $all = [
            'App\\Service\\Ignored',
            RealEvent::class,
            PlainEvent::class,
            WithInterface::class,
        ];
        $this->classIndex->method('all')->willReturn($all);

        $scanner = $this->container->make(EventScanner::class);
        $result = $scanner->scanEventClasses();

        $this->assertSame([RealEvent::class, PlainEvent::class], $result);
    }

    public function testScanEventClassesKeepsClassImplementingNonInterfaceSuffixInterface(): void
    {
        $all = [WithBuiltinContractEvent::class];
        $this->classIndex->method('all')->willReturn($all);

        $scanner = $this->container->make(EventScanner::class);
        $result = $scanner->scanEventClasses();

        $this->assertSame([WithBuiltinContractEvent::class], $result);
    }

    public function testIsEventClassReturnsFalseForInterfacesAndTrueForPlainClasses(): void
    {
        $scanner = new EventScannerHarness();

        $this->assertFalse($scanner->exposedIsEventClass(SomeInterface::class));
        $this->assertTrue($scanner->exposedIsEventClass(PlainEvent::class));
    }
}

class EventScannerHarness extends EventScanner
{
    public function exposedIsEventClass(string $fqcn): bool
    {
        return $this->isEventClass($fqcn);
    }
}
