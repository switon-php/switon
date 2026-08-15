<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\LlmTiming;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolTiming;
use Switon\Core\Json;

#[CoversClass(LlmTiming::class)]
#[CoversClass(ToolTiming::class)]
class TimingTest extends TestCase
{
    public function testLlmTimingRoundTripSkipsUnknownKeys(): void
    {
        $timing = LlmTiming::of(wall: 1.25, ttft: 0.2, reasoning: 0.5, start: 1700000000.5);
        $encoded = Json::parse(Json::stringify($timing));
        $encoded['noise'] = 'x';

        $loaded = LlmTiming::decode($encoded);

        $this->assertSame(1.25, $loaded->wall);
        $this->assertSame(0.2, $loaded->ttft);
        $this->assertSame(0.5, $loaded->reasoning);
        $this->assertSame(1700000000.5, $loaded->start);
        $this->assertSame([
            'wall' => 1.25,
            'ttft' => 0.2,
            'reasoning' => 0.5,
            'start' => 1700000000.5,
        ], $timing->jsonSerialize());
    }

    public function testToolTimingRoundTrip(): void
    {
        $timing = new ToolTiming(receive: 0.1, execute: 2.0);
        $loaded = ToolTiming::decode(Json::parse(Json::stringify($timing)) + ['noise' => 1]);

        $this->assertSame(0.1, $loaded->receive);
        $this->assertSame(2.0, $loaded->execute);
        $this->assertSame(['receive' => 0.1, 'execute' => 2.0], $timing->jsonSerialize());
    }

    public function testOfMeasuresAnnounceToArgsReady(): void
    {
        $timing = ToolTiming::of(new ToolCall('call_1', 'echo', [], [], 10.0, 12.5), 1.0);

        $this->assertSame(2.5, $timing->receive);
        $this->assertSame(1.0, $timing->execute);
        $this->assertSame(0.0, ToolTiming::of(new ToolCall('call_1', 'echo'), 0.0)->receive);
    }
}
