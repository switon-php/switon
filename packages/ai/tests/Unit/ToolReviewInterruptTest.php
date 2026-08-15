<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Container\ContainerInterface;
use Switon\Ai\AgentInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Ai\Suspension\ToolReviewContinuation;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\ToolCall;
use Switon\Ai\Suspension\ToolReviewInterrupt;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Exception\ToolDeniedException;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Ai\Message\UserMessage;
use Switon\Core\Exception;

#[CoversClass(ToolReviewInterrupt::class)]
class ToolReviewInterruptTest extends TestCase
{
    public function testOfCopiesCallFields(): void
    {
        $call = new ToolCall('call_1', 'echo', ['q' => 'x']);

        $interrupt = ToolReviewInterrupt::of($call);

        $this->assertSame('call_1', $interrupt->callId);
        $this->assertSame('echo', $interrupt->name);
        $this->assertSame(['q' => 'x'], $interrupt->arguments);
    }

    public function testAllowClearsSuspendWithoutTip(): void
    {
        $assistant = Completion::of(
            UserMessage::of('go'),
            new AssistantMessage('', [new ToolCall('call_1', 'echo', [])]),
        );
        $assistant->step = 1;
        $batch = Completion::of(ToolMessage::of([]));
        $batch->step = 2;
        $turn = Turn::of('t', completions: [$assistant, $batch], status: TurnStatus::Running);
        $interrupt = new ToolReviewInterrupt('call_1', 'echo', []);

        $interrupt->resume(
            $this->createStub(AgentInterface::class),
            $turn,
            ToolReviewContinuation::allow('call_1'),
            $this->createStub(ContainerInterface::class),
        );

        $this->assertNull($turn->interrupt);
        $this->assertSame(TurnStatus::Running, $turn->status);
        $this->assertCount(2, $turn->completions);
        $this->assertSame([], $batch->input->results);
        $this->assertTrue($turn->toolCall('call_1')->reviewed);
    }

    public function testDenyLandsToolTip(): void
    {
        $assistant = Completion::of(
            UserMessage::of('go'),
            new AssistantMessage('', [new ToolCall('call_1', 'echo', [])]),
        );
        $assistant->step = 1;
        $batch = Completion::of(ToolMessage::of([]));
        $batch->step = 2;
        $turn = Turn::of('t', completions: [$assistant, $batch], status: TurnStatus::Running);
        $interrupt = new ToolReviewInterrupt('call_1', 'echo', []);

        $interrupt->resume(
            $this->createStub(AgentInterface::class),
            $turn,
            ToolReviewContinuation::deny('call_1', 'no'),
            $this->createStub(ContainerInterface::class),
        );

        $this->assertSame(2, $batch->step);
        $this->assertCount(2, $turn->completions);
        $this->assertNull($turn->interrupt);
        $this->assertSame(TurnStatus::Running, $turn->status);
        $this->assertInstanceOf(ToolDeniedException::class, $batch->input->results[0]->content);
        $this->assertSame('Tool call denied: no', $batch->input->results[0]->content->getMessage());
        $this->assertTrue($turn->toolCall('call_1')->reviewed);
    }

    public function testDenyDefaultsMessageWhenReasonOmitted(): void
    {
        $assistant = Completion::of(
            UserMessage::of('go'),
            new AssistantMessage('', [new ToolCall('call_1', 'echo', [])]),
        );
        $assistant->step = 1;
        $batch = Completion::of(ToolMessage::of([]));
        $batch->step = 2;
        $turn = Turn::of('t', completions: [$assistant, $batch], status: TurnStatus::Running);
        $interrupt = new ToolReviewInterrupt('call_1', 'echo', []);

        $interrupt->resume(
            $this->createStub(AgentInterface::class),
            $turn,
            ToolReviewContinuation::deny('call_1'),
            $this->createStub(ContainerInterface::class),
        );

        $this->assertInstanceOf(ToolDeniedException::class, $batch->input->results[0]->content);
        $this->assertSame('Tool call denied.', $batch->input->results[0]->content->getMessage());
    }

    public function testResumeRejectsMismatchedCallId(): void
    {
        $assistant = Completion::of(
            UserMessage::of('go'),
            new AssistantMessage('', [new ToolCall('call_1', 'echo', [])]),
        );
        $assistant->step = 1;
        $turn = Turn::of('t', completions: [$assistant], status: TurnStatus::Suspended);
        $turn->interrupt = new ToolReviewInterrupt('call_1', 'echo', []);

        $this->expectException(Exception::class);
        $turn->interrupt->resume(
            $this->createStub(AgentInterface::class),
            $turn,
            ToolReviewContinuation::allow('call_other'),
            $this->createStub(ContainerInterface::class),
        );
    }
}
