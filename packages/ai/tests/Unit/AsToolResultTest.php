<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\AsToolCall;
use Switon\Ai\AsToolResult;
use Switon\Ai\Completion;
use Switon\Ai\LlmTiming;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\TokenUsage;
use Switon\Ai\ToolCall;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Ai\Message\UserMessage;

#[CoversClass(AsToolResult::class)]
#[CoversClass(AsToolCall::class)]
class AsToolResultTest extends TestCase
{
    public function testRoundTripKeepsCallAndTurnRollupFields(): void
    {
        $nested = Turn::of('child', status: TurnStatus::Completed);
        $nested->totals->llmCalls = 1;
        $nested->totals->llmWall = 1.0;
        $nested->totals->usage = new TokenUsage(1, 2);
        $nested->completions = [$this->llmStep('x', new TokenUsage(1, 2), 1.0)];

        $export = clone $nested;

        $wire = new ToolCall('c1', 'agent_explore', ['message' => 'go']);
        $call = new AsToolCall(
            id: $wire->id,
            name: $wire->name,
            threadId: 't10001',
            agent: 'explore',
            model: 'main',
        );
        $result = AsToolResult::from($call, $export, 'ok');
        $encoded = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        $decoded = $encoded['class']::decode($encoded);

        $this->assertInstanceOf(AsToolResult::class, $decoded);
        $this->assertInstanceOf(AsToolCall::class, $decoded->call);
        $this->assertSame(AsToolCall::class, $encoded['call']['class']);
        $this->assertSame('t10001', $decoded->call->threadId);
        $this->assertSame('explore', $decoded->call->agent);
        $this->assertSame('main', $decoded->call->model);
        $this->assertSame(
            ['class', 'id', 'name', 'threadId', 'agent', 'model'],
            array_keys($encoded['call']),
            'identity record carries only the five keys the transcript decoder reads',
        );
        $this->assertCount(1, $decoded->turn->completions);
        $this->assertSame('q', $decoded->turn->firstCompletion()->input->wire()[0]['text']);
        $this->assertSame(1, $decoded->turn->totals->llmCalls);
        $this->assertSame(1.0, $decoded->turn->totals->llmWall);
        $this->assertSame(3, $decoded->turn->totals->usage?->total());
    }

    public function testCloneIsolatesCallAndTurn(): void
    {
        $turn = Turn::of('child', status: TurnStatus::Completed);
        $turn->totals->llmCalls = 1;
        $call = new AsToolCall(id: 'c1', name: 'agent_explore', threadId: 't1', agent: 'explore', model: 'main');
        $result = AsToolResult::from($call, $turn, 'ok');

        $copy = clone $result;
        $copy->call->threadId = 't2';
        $copy->turn->totals->llmCalls = 9;

        $this->assertNotSame($result->call, $copy->call);
        $this->assertNotSame($result->turn, $copy->turn);
        $this->assertSame('t1', $result->call->threadId);
        $this->assertSame(1, $result->turn->totals->llmCalls);
        $this->assertSame('t2', $copy->call->threadId);
        $this->assertSame(9, $copy->turn->totals->llmCalls);
    }

    protected function llmStep(string $completeId, TokenUsage $usage, float $wall): Completion
    {
        $completion = Completion::of(UserMessage::of('q'), new AssistantMessage('a'));
        $completion->completeId = $completeId;
        $completion->usage = $usage;
        $completion->timing = LlmTiming::of($wall);

        return $completion;
    }
}
