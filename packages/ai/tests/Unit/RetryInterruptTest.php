<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Container\ContainerInterface;
use Switon\Ai\AgentInterface;
use Switon\Ai\Signal\AssistantAbortSignal;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Ai\Suspension\RetryContinuation;
use Switon\Ai\Suspension\RetryInterrupt;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Ai\Message\UserMessage;
use Switon\Core\Json;

#[CoversClass(RetryInterrupt::class)]
class RetryInterruptTest extends TestCase
{
    public function testDecodeRoundTripPreservesReasonAndDetail(): void
    {
        $interrupt = new RetryInterrupt(RetryInterrupt::REASON_CONTEXT_OVERFLOW, 'window full');
        $encoded = Json::parse(Json::stringify($interrupt));
        /** @var class-string<RetryInterrupt> $class */
        $class = $encoded['class'];
        $loaded = $class::decode($encoded);

        $this->assertInstanceOf(RetryInterrupt::class, $loaded);
        $this->assertSame(RetryInterrupt::REASON_CONTEXT_OVERFLOW, $loaded->reason);
        $this->assertSame('window full', $loaded->detail);
    }

    public function testAcceptClearsSuspendAndResetsHungTip(): void
    {
        $hung = Completion::of(UserMessage::of('hi'), new AssistantMessage('partial'));
        $hungId = $hung->completeId;
        $turn = Turn::of('t', completions: [$hung], status: TurnStatus::Running);
        $interrupt = new RetryInterrupt(RetryInterrupt::REASON_LLM_FAILED, 'boom');

        $interrupt->resume(
            $this->createStub(AgentInterface::class),
            $turn,
            RetryContinuation::of(),
            $this->createStub(ContainerInterface::class),
        );

        $this->assertNull($turn->interrupt);
        $this->assertSame(TurnStatus::Running, $turn->status);
        $this->assertSame($hungId, $hung->completeId);
        $this->assertNull($hung->output);
        $this->assertNull($hung->finishReason);
    }

    public function testRejectSettlesTurnWithoutTip(): void
    {
        $hung = Completion::of(UserMessage::of('hi'));
        $turn = Turn::of('t', completions: [$hung], status: TurnStatus::Running);
        $interrupt = new RetryInterrupt(RetryInterrupt::REASON_LLM_FAILED, 'boom');

        $interrupt->resume(
            $this->createStub(AgentInterface::class),
            $turn,
            RetryContinuation::of(RetryContinuation::DECISION_REJECT),
            $this->container,
        );

        $this->assertNull($turn->interrupt);
        $this->assertSame(TurnStatus::Aborted, $turn->status);
        $this->assertInstanceOf(AssistantAbortSignal::class, $hung->output);
        $this->assertSame(AssistantAbortSignal::KIND_EXCEPTION, $hung->output->kind);
        $this->assertSame('boom', $hung->output->body);
    }
}
