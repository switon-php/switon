<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Container\ContainerInterface;
use Switon\Ai\AgentInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Ai\Suspension\ToolReplyContinuation;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\ToolCall;
use Switon\Ai\Suspension\ToolInterrupt;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Suspension\ToolReplyInterrupt;
use Switon\Ai\ToolResult;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Ai\Message\UserMessage;
use Switon\Core\Exception;
use Switon\Core\Json;

#[CoversClass(ToolReplyInterrupt::class)]
#[CoversClass(ToolInterrupt::class)]
class ToolReplyInterruptTest extends TestCase
{
    public function testOfCopiesCallFields(): void
    {
        $interrupt = ToolReplyInterrupt::of(new ToolCall('call_1', 'form_ask', ['id' => 'f1']));

        $this->assertSame('call_1', $interrupt->callId);
        $this->assertSame('form_ask', $interrupt->name);
        $this->assertSame(['id' => 'f1'], $interrupt->arguments);
    }

    public function testDecodeRoundTrip(): void
    {
        $interrupt = new ToolReplyInterrupt('call_2', 'custom', ['k' => 1]);
        $encoded = Json::parse(Json::stringify($interrupt));
        /** @var class-string<ToolReplyInterrupt> $class */
        $class = $encoded['class'];
        $loaded = $class::decode($encoded);

        $this->assertInstanceOf(ToolReplyInterrupt::class, $loaded);
        $this->assertSame('call_2', $loaded->callId);
        $this->assertSame('custom', $loaded->name);
        $this->assertSame(['k' => 1], $loaded->arguments);
    }

    public function testResumeLandsToolTipAndClearsSuspend(): void
    {
        $call = new ToolCall('call_1', 'form_ask', ['id' => 'f1']);
        $assistant = Completion::of(UserMessage::of('go'), new AssistantMessage('', [$call]));
        $assistant->step = 3;
        $batch = Completion::of(ToolMessage::of([]));
        $batch->step = 4;
        $turn = Turn::of('t', completions: [$assistant, $batch], status: TurnStatus::Running);
        $interrupt = ToolReplyInterrupt::of($call);

        $interrupt->resume(
            $this->createStub(AgentInterface::class),
            $turn,
            ToolReplyContinuation::of('call_1', ['ok' => true]),
            $this->createStub(ContainerInterface::class),
        );

        $this->assertNull($turn->interrupt);
        $this->assertSame(TurnStatus::Running, $turn->status);
        $this->assertSame(['ok' => true], $batch->input->results[0]->content);
        $this->assertSame(4, $batch->step);
        $this->assertCount(2, $turn->completions);
    }

    public function testResumeRejectsMismatchedCallId(): void
    {
        $call = new ToolCall('call_1', 'form_ask', []);
        $assistant = Completion::of(UserMessage::of('go'), new AssistantMessage('', [$call]));
        $turn = Turn::of('t', completions: [$assistant], status: TurnStatus::Suspended);
        $turn->interrupt = ToolReplyInterrupt::of($call);

        $this->expectException(Exception::class);
        $turn->interrupt->resume(
            $this->createStub(AgentInterface::class),
            $turn,
            ToolReplyContinuation::of('call_other', 'x'),
            $this->createStub(ContainerInterface::class),
        );
    }

    public function testToolCallRaisesWhenToolCallMissing(): void
    {
        $turn = Turn::of('t', completions: [
            Completion::of(UserMessage::of('go'), new AssistantMessage('no tools')),
        ], status: TurnStatus::Suspended);

        $this->expectException(Exception::class);
        $turn->toolCall('missing');
    }

    public function testToolCallSkipsNonAssistantOutputs(): void
    {
        $call = new ToolCall('call_1', 'echo', []);
        $turn = Turn::of('t', completions: [
            Completion::of(UserMessage::of('go'), new AssistantMessage('', [$call])),
            Completion::of(ToolMessage::of([ToolResult::of($call, 'later')])),
        ], status: TurnStatus::Suspended);

        $found = $turn->toolCall('call_1');

        $this->assertSame('call_1', $found->id);
        $this->assertSame('echo', $found->name);
    }
}
