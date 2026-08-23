<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;
use Switon\Agency\Agent;
use Switon\Agency\Exception\RunCancelledException;
use Swoole\Coroutine\Channel;
use Switon\Agency\Loop;
use Switon\Agency\Tests\Fixtures\StubTool;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Envelope;
use Switon\Ai\Message;
use Switon\Ai\StreamDelta;
use Switon\Ai\LlmInterface;
use Switon\Ai\Thread;
use Switon\Tooling\ToolsInterface;
use Switon\Ai\Message\UserMessage;
use Switon\Tooling\Tools;

#[CoversClass(Loop::class)]
class StepStreamingTest extends TestCase
{
    public function testDriveInvokesAgentOnDelta(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('hello');
        $this->container->set(LlmInterface::class, $stub);

        $received = [];
        $agent = $this->make(Agent::class, [
            'onDelta' => static function (StreamDelta $delta) use (&$received): void {
                $received[] = $delta->content;
            },
        ]);

        $turn = $this->runForTest($agent, UserMessage::of('go'));

        $this->assertSame(1, $stub->onDeltaCalls);
        $this->assertSame(['stream-delta'], $received);
        $this->assertFalse($turn->lastCompletion()->isCancelled());
        $this->assertSame('hello', $turn->lastCompletion()->output?->content);
    }

    public function testDriveAbortsTheStepWhenCancelFlipsDuringTheStream(): void
    {
        $stub = new StubLlm();
        $stub->streamDeltas = ['a', 'b', 'c'];
        $stub->responses[] = StubLlm::assistant('abc');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, []);
        $stub->onStreamDelta = static function () use ($agent): void {
            (new ReflectionProperty(Agent::class, 'cancel'))->setValue($agent, new Channel(1));
        };

        try {
            $this->runForTest($agent, UserMessage::of('go'));
            $this->fail('Cancel during the stream should fail the run.');
        } catch (RunCancelledException) {
        }

        $this->assertSame(2, $stub->shouldAbortPolls);
        $this->assertTrue($agent->thread()->lastTurn()->lastCompletion()->isCancelled());
    }

    public function testDriveSkipsTheModelWhenAlreadyCancelled(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('hello');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, []);
        (new ReflectionProperty(Agent::class, 'cancel'))->setValue($agent, new Channel(1));

        try {
            $this->runForTest($agent, UserMessage::of('go'));
            $this->fail('A cancelled agent should not finish the run.');
        } catch (RunCancelledException) {
        }

        $this->assertSame(0, $stub->streamCalls);
    }

    public function testDeliveryEffortLandsOnTheLiveCharterEveryStep(): void
    {
        $stub = new StubLlm();
        $seen = [];
        $stub->responses = [
            static function (Thread $thread, Message $input, Charter $charter) use (&$seen): Completion {
                $seen[] = $charter->effort;

                return StubLlm::assistantWithToolCall('loop', callId: 'call_effort_1');
            },
            static function (Thread $thread, Message $input, Charter $charter) use (&$seen): Completion {
                $seen[] = $charter->effort;

                return StubLlm::assistant('done');
            },
        ];

        $toolsRegistry = $this->make(Tools::class, [
            'tools' => [
                'loop' => StubTool::config('loop', 'Loop', static fn (): string => 'ok'),
            ],
        ]);

        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $toolsRegistry);

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['loop'], maxSteps: 4),
        ]);

        $agent->run(Envelope::of(UserMessage::of('go'), $this->modelForTest($agent), 'high'));

        // Two model steps; the second follows compaction, which may reassemble the charter.
        $this->assertSame(['high', 'high'], $seen);
    }

    public function testCharterEffortIsAbsentWhenTheDeliverySendsNone(): void
    {
        $stub = new StubLlm();
        $seen = [];
        $stub->responses[] = static function (Thread $thread, Message $input, Charter $charter) use (&$seen): Completion {
            $seen[] = $charter->effort;

            return StubLlm::assistant('done');
        };

        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, ['profile' => $this->testProfile()]);
        $this->runForTest($agent, UserMessage::of('go'));

        $this->assertSame([null], $seen);
    }
}
