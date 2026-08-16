<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\ToolCall;
use Switon\Core\ClockInterface;
use Switon\Testing\MockClock;
use Switon\Tooling\Tests\ToolTestCase;
use Switon\Tooling\Tool\SleepTool;

#[CoversClass(SleepTool::class)]
class SleepToolTest extends ToolTestCase
{
    public function testCallWaitsAndReturnsTimestamps(): void
    {
        $clock = new MockClock(1700000000);
        $this->container->replace(ClockInterface::class, $clock);

        $tool = $this->make(SleepTool::class);
        $result = $this->callTool($tool, 'sleep', ['seconds' => 1]);

        $this->assertArrayNotHasKey('seconds', $result);
        $this->assertSame(1700000000, strtotime($result['started_at']));
        $this->assertSame(1700000001, strtotime($result['woke_at']));
    }

    public function testCallAcceptsFractionalSeconds(): void
    {
        $clock = new MockClock(1700000000.0);
        $this->container->replace(ClockInterface::class, $clock);

        $this->callTool($this->make(SleepTool::class), 'sleep', ['seconds' => 0.5]);

        $this->assertSame(1700000000.5, $clock->microtime());
    }

    public function testCallReturnsWhenTheCallIsCancelled(): void
    {
        $clock = new MockClock(1700000000);
        $this->container->replace(ClockInterface::class, $clock);
        $call = new ToolCall(id: 'c', name: 'sleep', arguments: ['seconds' => 1]);
        $call->cancelled = true;

        $result = $this->make(SleepTool::class)->call(
            $this->toolAgent(),
            $call,
            ['seconds' => 1],
        );

        $this->assertTrue($result['cancelled']);
        $this->assertSame(1700000000, strtotime($result['started_at']));
        $this->assertSame(1700000000, strtotime($result['woke_at']));
    }

    public function testSchemaIsSecondsOnly(): void
    {
        $schema = $this->make(SleepTool::class)->schema('sleep');

        $this->assertSame('Wait in-process (backoff/timers).', $schema->definition['description']);
        $this->assertSame(
            ['type' => 'number', 'description' => 'Non-zero delay in seconds.', 'exclusiveMinimum' => 0],
            $schema->definition['inputSchema']['properties']['seconds'],
        );
        $this->assertSame(['seconds'], $schema->definition['inputSchema']['required']);
        $this->assertTrue($this->make(SleepTool::class)->isReadonly('sleep', ['seconds' => 1]));
    }
}
