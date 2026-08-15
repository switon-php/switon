<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\StreamDelta;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\TokenUsage;
use Switon\Ai\ToolCallDelta;

#[CoversClass(StreamDelta::class)]
#[CoversClass(ToolCallDelta::class)]
class StreamDeltaTest extends TestCase
{
    public function testFragmentKeepsWireFields(): void
    {
        $delta = new StreamDelta(
            id: 'chunk-1',
            content: 'Hi',
            reasoning: '',
            finishReason: 'stop',
            usage: new TokenUsage(1, 2),
            toolCallDeltas: [new ToolCallDelta('call_1', 'echo', '{"n":', ['type' => 'function'], 1.5)],
            passback: ['trace' => 't1'],
            refusal: 'nope',
        );

        $this->assertNull($delta->at);
        $this->assertSame('chunk-1', $delta->id);
        $this->assertSame('Hi', $delta->content);
        $this->assertSame('', $delta->reasoning);
        $this->assertSame('stop', $delta->finishReason);
        $this->assertSame(1, $delta->usage?->input);
        $this->assertSame('call_1', $delta->toolCallDeltas[0]->id);
        $this->assertSame('echo', $delta->toolCallDeltas[0]->name);
        $this->assertSame('{"n":', $delta->toolCallDeltas[0]->partialJson);
        $this->assertSame(['type' => 'function'], $delta->toolCallDeltas[0]->passback);
        $this->assertSame(1.5, $delta->toolCallDeltas[0]->announcedAt);
        $this->assertSame(['trace' => 't1'], $delta->passback);
        $this->assertSame('nope', $delta->refusal);
    }

    public function testToolCallDeltaJsonRoundTripKeepsEveryField(): void
    {
        $delta = new ToolCallDelta('call_1', 'echo', '{"n":', ['type' => 'function'], 1.5);

        $this->assertSame([
            'class' => ToolCallDelta::class,
            'id' => 'call_1',
            'name' => 'echo',
            'partialJson' => '{"n":',
            'passback' => ['type' => 'function'],
            'announcedAt' => 1.5,
        ], $delta->jsonSerialize());

        $decoded = ToolCallDelta::decode($delta->jsonSerialize());
        $this->assertSame('call_1', $decoded->id);
        $this->assertSame('echo', $decoded->name);
        $this->assertSame('{"n":', $decoded->partialJson);
        $this->assertSame(['type' => 'function'], $decoded->passback);
        $this->assertSame(1.5, $decoded->announcedAt);
    }
}
