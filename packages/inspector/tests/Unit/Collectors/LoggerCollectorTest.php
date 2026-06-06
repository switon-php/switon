<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit\Collectors;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use Switon\Core\ContextManagerInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Inspector\Collector\LoggerCollector;
use Switon\Inspector\Collector\LoggerCollectorContext;
use Switon\Logging\Event\LoggerLogged;
use Switon\Logging\Level;
use Switon\Logging\LogEntry;
use Switon\Testing\TestCase;

#[AllowMockObjectsWithoutExpectations]
class LoggerCollectorTest extends TestCase
{
    public function testBootRegistersCollectorAsListener(): void
    {
        $context = new LoggerCollectorContext();
        $contextManager = $this->createMock(ContextManagerInterface::class);
        $contextManager->method('getContext')->willReturn($context);

        $listenerProvider = $this->createMock(ListenerProviderInterface::class);
        $listenerProvider->expects($this->once())
            ->method('register')
            ->with($this->isInstanceOf(LoggerCollector::class));

        $collector = $this->make(LoggerCollector::class, [
            'contextManager' => $contextManager,
            'listenerProvider' => $listenerProvider,
        ]);

        $collector->boot();
    }

    public function testOnLoggerLoggedAppendsFormattedRowToContext(): void
    {
        $context = new LoggerCollectorContext();
        $contextManager = $this->createMock(ContextManagerInterface::class);
        $contextManager->method('getContext')->willReturn($context);

        $collector = $this->make(LoggerCollector::class, [
            'contextManager' => $contextManager,
            'listenerProvider' => $this->createMock(ListenerProviderInterface::class),
        ]);

        $entry = new LogEntry('info', 'host', 'H:i:s.v', 1700000000.456);
        $entry->category = 'app.auth';
        $entry->file = 'Auth.php';
        $entry->line = 42;
        $entry->message = 'login ok';

        $event = new LoggerLogged(
            $this->createMock(LoggerInterface::class),
            'info',
            'login ok',
            [],
            $entry
        );

        $collector->onLoggerLogged($event);

        $this->assertCount(1, $context->logs);
        $row = $context->logs[0];
        $this->assertSame('info', $row['level']);
        $this->assertSame('app.auth', $row['category']);
        $this->assertSame('Auth.php', $row['file']);
        $this->assertSame(42, $row['line']);
        $this->assertSame('login ok', $row['message']);
        $this->assertStringEndsWith('.456', $row['time']);
    }

    public function testCollectReturnsLastMaxLogsAndKeepsTotalCount(): void
    {
        $context = new LoggerCollectorContext();
        $context->logs = [
            ['time' => '10:00:00.001', 'level' => 'debug', 'category' => 'a', 'file' => 'A.php', 'line' => 1, 'message' => 'm1'],
            ['time' => '10:00:00.002', 'level' => 'info', 'category' => 'b', 'file' => 'B.php', 'line' => 2, 'message' => 'm2'],
            ['time' => '10:00:00.003', 'level' => 'error', 'category' => 'c', 'file' => 'C.php', 'line' => 3, 'message' => 'm3'],
        ];

        $contextManager = $this->createMock(ContextManagerInterface::class);
        $contextManager->method('getContext')->willReturn($context);

        $collector = $this->make(LoggerCollector::class, [
            'contextManager' => $contextManager,
            'listenerProvider' => $this->createMock(ListenerProviderInterface::class),
            'limit' => 2,
        ]);

        $result = $collector->collect();

        $this->assertSame(3, $result['total_count']);
        $this->assertCount(2, $result['logs']);
        $this->assertSame('m2', $result['logs'][0]['message']);
        $this->assertSame('m3', $result['logs'][1]['message']);
        $this->assertSame(Level::map(), $result['levels']);
        $this->assertSame(Level::map()['debug'], $result['default_level']);
    }

    public function testJsonSerializeReturnsEmptyArray(): void
    {
        $collector = new LoggerCollector();
        $this->assertSame([], $collector->jsonSerialize());
    }
}
