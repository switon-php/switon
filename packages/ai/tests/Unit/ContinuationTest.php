<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\DiscardTurnLetter;
use Switon\Ai\Suspension\Continuation;
use Switon\Ai\Suspension\RetryContinuation;
use Switon\Ai\Suspension\RetryInterrupt;
use Switon\Ai\Suspension\ToolReviewContinuation;
use Switon\Ai\Suspension\ToolReviewInterrupt;
use Switon\Ai\Suspension\ToolReplyContinuation;
use Switon\Ai\Suspension\ToolReplyInterrupt;
use Switon\Ai\Tests\TestCase;

#[CoversClass(Continuation::class)]
#[CoversClass(RetryContinuation::class)]
#[CoversClass(ToolReviewContinuation::class)]
#[CoversClass(ToolReplyContinuation::class)]
#[CoversClass(DiscardTurnLetter::class)]
class ContinuationTest extends TestCase
{
    public function testRetryContinuationFactories(): void
    {
        $accept = RetryContinuation::of();
        $reject = RetryContinuation::of(RetryContinuation::DECISION_REJECT);

        $this->assertSame(RetryContinuation::DECISION_ACCEPT, $accept->decision);
        $this->assertSame(RetryContinuation::DECISION_REJECT, $reject->decision);
    }

    public function testToolReviewContinuationAllowAndDeny(): void
    {
        $allow = ToolReviewContinuation::allow('call_1');
        $deny = ToolReviewContinuation::deny('call_1', 'no');

        $this->assertSame('call_1', $allow->callId);
        $this->assertSame(ToolReviewContinuation::DECISION_ALLOW, $allow->decision);
        $this->assertSame('', $allow->reason);
        $this->assertSame(ToolReviewContinuation::DECISION_DENY, $deny->decision);
        $this->assertSame('no', $deny->reason);
    }

    public function testToolReplyAndDiscardTurnFactories(): void
    {
        $reply = ToolReplyContinuation::of('call_2', ['ok' => true]);
        $discard = DiscardTurnLetter::of('turn-9');

        $this->assertSame('call_2', $reply->callId);
        $this->assertSame(['ok' => true], $reply->reply);
        $this->assertSame('turn-9', $discard->turnId);
    }

    public function testEachContinuationNamesTheInterruptItResumes(): void
    {
        $this->assertSame(RetryInterrupt::class, RetryContinuation::of()->pairsWith());
        $this->assertSame(ToolReviewInterrupt::class, ToolReviewContinuation::allow('call_1')->pairsWith());
        $this->assertSame(ToolReplyInterrupt::class, ToolReplyContinuation::of('call_1', 'ok')->pairsWith());
    }

    /** Discard resumably drops a turn — it resumes nothing, so it is a letter, not a continuation. */
    public function testDiscardTurnLetterIsNotAContinuation(): void
    {
        $this->assertNotInstanceOf(Continuation::class, DiscardTurnLetter::of('turn-9'));
    }
}
