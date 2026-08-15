<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Container\ContainerInterface;
use Switon\Ai\AgentInterface;
use Switon\Ai\DiscardTurnLetter;
use Switon\Ai\Suspension\Interrupt;
use Switon\Ai\Suspension\RetryContinuation;
use Switon\Ai\Suspension\RetryInterrupt;
use Switon\Ai\Suspension\ToolReviewContinuation;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Core\Json;

#[CoversClass(Interrupt::class)]
#[CoversClass(Turn::class)]
class InterruptTest extends TestCase
{
    public function testReviewMailDoesNotPairWithRetryInterrupt(): void
    {
        $turn = Turn::of('t1', status: TurnStatus::Suspended);
        $turn->interrupt = new RetryInterrupt(RetryInterrupt::REASON_LLM_FAILED, 'boom');
        $mail = ToolReviewContinuation::allow('call_1');

        $this->assertFalse($turn->interrupt instanceof ($mail->pairsWith()));
        $this->assertSame(TurnStatus::Suspended, $turn->status);
    }

    /**
     * Discard is a letter but not a continuation — the type system rejects it before any
     * pairing check could run, which is the point of splitting the two.
     */
    public function testDiscardLetterCannotResumeASuspend(): void
    {
        $turn = Turn::of('t1', status: TurnStatus::Suspended);
        $turn->interrupt = new RetryInterrupt();

        $this->expectException(\TypeError::class);

        /** @phpstan-ignore-next-line intentional type violation */
        $turn->interrupt->resume(
            $this->createStub(AgentInterface::class),
            $turn,
            DiscardTurnLetter::of('t1'),
            $this->createStub(ContainerInterface::class),
        );
    }

    public function testResumePassesPairedMailToTheMechanism(): void
    {
        $turn = Turn::of('t1', status: TurnStatus::Suspended);
        $turn->interrupt = new RetryInterrupt(RetryInterrupt::REASON_LLM_FAILED, 'boom');

        $turn->interrupt->resume(
            $this->createStub(AgentInterface::class),
            $turn,
            RetryContinuation::of(RetryContinuation::DECISION_REJECT),
            $this->container,
        );

        $this->assertNull($turn->interrupt);
        $this->assertSame(TurnStatus::Aborted, $turn->status);
    }

    public function testSuspendedAtRoundTripsThroughJson(): void
    {
        $turn = Turn::of('t1');
        $interrupt = new RetryInterrupt(RetryInterrupt::REASON_CONTEXT_OVERFLOW, 'big');
        $interrupt->suspendedAt = 1_700_000_000.5;
        $turn->interrupt = $interrupt;
        $turn->status = TurnStatus::Suspended;

        $bag = Json::parse(Json::stringify($turn));
        $loaded = Turn::decode($bag);

        $this->assertInstanceOf(RetryInterrupt::class, $loaded->interrupt);
        $this->assertSame(1_700_000_000.5, $loaded->interrupt->suspendedAt);
        $this->assertSame(TurnStatus::Suspended, $loaded->status);
    }
}
