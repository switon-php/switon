<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Signal\AssistantAbortSignal;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\ToolResult;
use Switon\Ai\TurnStatus;
use Switon\Core\Json;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Model;
use Switon\Ai\Completion;
use Switon\Ai\Exception\ToolAbortedException;
use Switon\Ai\ToolCall;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Suspension\ToolReplyInterrupt;
use Switon\Ai\TokenUsage;
use Switon\Ai\Turn;
use Switon\Ai\Message\UserMessage;
use ValueError;

#[CoversClass(Turn::class)]
class TurnTest extends TestCase
{
    public function testJsonSerializeDecodeRoundTrip(): void
    {
        $turn = Turn::of('t1', [
            Completion::of(UserMessage::of('Hi'), new AssistantMessage('Hello')),
        ], TurnStatus::Completed, model: 'main', effort: 'high');
        $turn->totals->usage = new TokenUsage(1, 2);
        $turn->attributes = ['trace' => 'abc'];

        $encoded = $turn->jsonSerialize();
        $this->assertSame('completions', array_key_last($encoded));

        $loaded = Turn::decode(Json::parse(Json::stringify($turn)));

        $this->assertSame('t1', $loaded->turnId);
        $this->assertSame(TurnStatus::Completed, $loaded->status);
        $this->assertSame('main', $loaded->model);
        $this->assertSame('high', $loaded->effort);
        $this->assertSame('Hi', $loaded->completions[0]->input->wire()[0]['text']);
        $this->assertSame(1, $loaded->totals->usage?->input);
        $this->assertSame(['trace' => 'abc'], $loaded->attributes);
    }

    public function testTipCompletions(): void
    {
        $first = Completion::of(UserMessage::of('a'), new AssistantMessage('b'));
        $last = Completion::of(UserMessage::of('c'), new AssistantMessage('d'));
        $turn = Turn::of('t1', [$first, $last], TurnStatus::Completed);

        $this->assertSame($first, $turn->firstCompletion());
        $this->assertSame($last, $turn->lastCompletion());
    }

    public function testInterruptRoundTripsWithTurn(): void
    {
        $turn = Turn::of('t1', [Completion::of(UserMessage::of('q'))], TurnStatus::Suspended, 'main');
        $turn->interrupt = new ToolReplyInterrupt('call_1', 'form_ask', ['id' => 'form-1']);

        $loaded = Turn::decode(Json::parse(Json::stringify($turn)));

        $this->assertSame(TurnStatus::Suspended, $loaded->status);
        $this->assertInstanceOf(ToolReplyInterrupt::class, $loaded->interrupt);
        $this->assertSame('call_1', $loaded->interrupt->callId);
        $this->assertSame('form_ask', $loaded->interrupt->name);
        $this->assertSame('form-1', $loaded->interrupt->arguments['id'] ?? null);
    }

    public function testSettledStatuses(): void
    {
        $completion = Completion::of(UserMessage::of('hi'));
        $turn = Turn::of('t1', [$completion]);
        $this->assertFalse($turn->isSettled());
        $this->assertSame($completion, $turn->lastCompletion());

        $turn->status = TurnStatus::Completed;
        $this->assertTrue($turn->isSettled());

        $turn->status = TurnStatus::Aborted;
        $this->assertTrue($turn->isSettled());
    }

    public function testCloneIsolatesCompletionsUsageAndInterrupt(): void
    {
        $turn = Turn::of('t1', [
            Completion::of(UserMessage::of('a'), new AssistantMessage('b')),
        ], TurnStatus::Suspended);
        $turn->totals->usage = new TokenUsage(1, 2);
        $turn->interrupt = new ToolReplyInterrupt('call_1', 'echo', []);

        $copy = clone $turn;
        $copy->completions[0]->input = UserMessage::of('changed');
        $copy->totals->usage->input = 9;
        $copy->interrupt->callId = 'call_x';

        $this->assertSame('a', $turn->completions[0]->input->wire()[0]['text']);
        $this->assertSame(1, $turn->totals->usage?->input);
        $this->assertSame('call_1', $turn->interrupt?->callId);
    }

    public function testDecodeSkipsUnknownKeys(): void
    {
        $turn = Turn::of('t1', [Completion::of(UserMessage::of('q'))], TurnStatus::Completed);
        $bag = Json::parse(Json::stringify($turn));
        $bag['noise'] = true;

        $loaded = Turn::decode($bag);

        $this->assertSame('t1', $loaded->turnId);
        $this->assertSame(TurnStatus::Completed, $loaded->status);
    }

    public function testDecodeRestoresTurnStatusFromStoredString(): void
    {
        $turn = Turn::of('t1', [Completion::of(UserMessage::of('q'))], TurnStatus::Suspended);
        $bag = Json::parse(Json::stringify($turn));

        $this->assertSame('suspended', $bag['status']);

        $loaded = Turn::decode($bag);

        $this->assertSame(TurnStatus::Suspended, $loaded->status);
    }

    public function testDecodeRejectsUnknownStoredStatus(): void
    {
        $bag = Json::parse(Json::stringify(Turn::of('t1', [Completion::of(UserMessage::of('q'))])));
        $bag['status'] = 'done';

        $this->expectException(ValueError::class);

        Turn::decode($bag);
    }

    public function testCloseLandsTheSignalOnTheAbortedToolRow(): void
    {
        $call = new ToolCall('call_1', 'echo');
        $assistant = Completion::of(UserMessage::of('go'), new AssistantMessage('', [$call]));
        $assistant->step = 1;
        $turn = Turn::of('t1', [$assistant]);
        $signal = new AssistantAbortSignal(AssistantAbortSignal::KIND_CANCELLED, body: 'stop');

        $turn->close($signal, 10.0);

        $closure = $turn->lastCompletion();
        $this->assertNotSame($assistant, $closure);
        $this->assertInstanceOf(ToolMessage::class, $closure->input);
        $this->assertSame('call_1', $closure->input->results[0]->id);
        $this->assertSame($signal, $closure->output);
        $this->assertSame(10.0, $closure->landedAt);
        $this->assertSame(TurnStatus::Cancelled, $turn->status);
    }

    public function testCloseWritesTheSignalOntoAHungTip(): void
    {
        $hung = Completion::of(UserMessage::of('go'));
        $turn = Turn::of('t1', [$hung]);
        $signal = new AssistantAbortSignal(AssistantAbortSignal::KIND_EXCEPTION, body: 'boom');

        $turn->close($signal, 4.0);

        $this->assertCount(1, $turn->completions);
        $this->assertSame($signal, $hung->output);
        $this->assertSame(4.0, $hung->landedAt);
        $this->assertSame(TurnStatus::Aborted, $turn->status);
    }

    public function testCloseFillsAPartialToolRowThenLandsTheSignalThere(): void
    {
        $done = new ToolCall('call_1', 'echo');
        $open = new ToolCall('call_2', 'echo');
        $assistant = Completion::of(UserMessage::of('go'), new AssistantMessage('', [$done, $open]));
        $assistant->step = 1;
        $tools = Completion::of(ToolMessage::of([ToolResult::of($done, 'ok')]));
        $tools->step = 2;
        $turn = Turn::of('t1', [$assistant, $tools]);
        $signal = new AssistantAbortSignal(AssistantAbortSignal::KIND_CANCELLED, body: 'stop');

        $turn->close($signal, 8.0);

        $this->assertCount(2, $turn->completions);
        $this->assertSame($signal, $tools->output);
        $this->assertSame(8.0, $tools->landedAt);
        $this->assertCount(2, $tools->input->results);
        $this->assertSame('call_2', $tools->input->results[1]->id);
        $this->assertInstanceOf(ToolAbortedException::class, $tools->input->results[1]->content);
        $this->assertSame(TurnStatus::Cancelled, $turn->status);
    }

    public function testCloseWritesTheSignalWhenEveryToolResultIsPresent(): void
    {
        $call = new ToolCall('call_1', 'echo');
        $assistant = Completion::of(UserMessage::of('go'), new AssistantMessage('', [$call]));
        $assistant->step = 1;
        $tools = Completion::of(ToolMessage::of([ToolResult::of($call, 'ok')]));
        $tools->step = 2;
        $turn = Turn::of('t1', [$assistant, $tools]);
        $signal = new AssistantAbortSignal(AssistantAbortSignal::KIND_BUDGET_EXCEEDED, body: 'budget');

        $turn->close($signal, 6.0);

        $this->assertCount(2, $turn->completions);
        $this->assertCount(1, $tools->input->results);
        $this->assertSame($signal, $tools->output);
        $this->assertSame(TurnStatus::Aborted, $turn->status);
    }
}
