<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\ToolCall;

#[CoversClass(ToolCall::class)]
class ToolCallTest extends TestCase
{
    public function testDecodeRoundTripsPassbackFields(): void
    {
        $decoded = ToolCall::decode([
            'id' => 'call_1',
            'name' => 'get_weather',
            'arguments' => ['location' => 'SF', '.caption' => 'Checking weather.'],
            'passback' => ['type' => 'function', 'trace_id' => 'trace-1'],
        ]);

        $this->assertSame('call_1', $decoded->id);
        $this->assertSame('get_weather', $decoded->name);
        $this->assertSame(
            ['location' => 'SF', '.caption' => 'Checking weather.'],
            $decoded->arguments,
        );
        $this->assertSame(['type' => 'function', 'trace_id' => 'trace-1'], $decoded->passback);
        $this->assertArrayHasKey('arguments', get_object_vars($decoded));
    }

    public function testDecodeRestoresAttributes(): void
    {
        $decoded = ToolCall::decode([
            'id' => 'call_1',
            'name' => 'echo',
            'attributes' => ['ui' => 'chip'],
        ]);

        $this->assertSame(['ui' => 'chip'], $decoded->attributes);
    }

    public function testDecodeIgnoresLegacyAgentField(): void
    {
        $decoded = ToolCall::decode([
            'id' => 'call_1',
            'name' => 'echo',
            'agent' => 't10001',
        ]);

        $this->assertSame('call_1', $decoded->id);
        $this->assertSame('echo', $decoded->name);
        $this->assertFalse(property_exists($decoded, 'agent'));
    }

    public function testConstructorKeepsDotKeysInArguments(): void
    {
        $call = new ToolCall('1', 'echo', ['x' => 1, '.caption' => 'Run echo.']);

        $this->assertSame(['x' => 1, '.caption' => 'Run echo.'], $call->arguments);
    }

    public function testArgumentsDefaultToEmptyArray(): void
    {
        $this->assertSame([], (new ToolCall('1', 'echo'))->arguments);
        $this->assertSame(['x' => 1], (new ToolCall('1', 'echo', ['x' => 1]))->arguments);
    }

    public function testCancelledRoundTrips(): void
    {
        $call = new ToolCall('call_1', 'echo');
        $call->cancelled = true;

        $this->assertTrue($call->jsonSerialize()['cancelled']);
        $this->assertTrue(ToolCall::decode($call->jsonSerialize())->cancelled);
    }
}
