<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Agent;
use Switon\Ai\Signal\AssistantSignal;
use Switon\Ai\Event\DeliverySettled;
use Switon\Ai\Event\DeliveryStarted;
use Switon\Agency\Exception;
use Switon\Agency\Exception\DeliveryException;
use Switon\Agency\Tests\Fixtures\BlockingTool;
use Switon\Agency\Tests\Fixtures\FastInboxAgent;
use Switon\Agency\Tests\Fixtures\RecordingRootAgent;
use Switon\Agency\Tests\Fixtures\StubTool;
use Switon\Agency\Tests\Fixtures\ThrowOnTaskFinishDeliverAgent;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Ai\TurnStatus;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;
use Switon\Ai\Signal\AssistantAbortSignal;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Llm\Exception\HttpStatusException;
use Switon\Ai\LlmInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\ToolResult;
use Switon\Ai\Envelope;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Turn;
use Switon\Eventing\EventDispatcherInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Switon\Agency\Exception\RunCancelledException;
use Switon\Ai\TokenUsage;
use RuntimeException;
use Throwable;

/** Cancel/shutdown paths and inbox enqueue backpressure on {@see Agent}. */
#[CoversClass(Agent::class)]
#[Group('coroutine')]
class AgentRuntimeTest extends TestCase
{
    protected function setUpContainer(): void
    {
        parent::setUpContainer();
        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => [
                'block' => BlockingTool::class,
                ...$this->builtinToolConfig(),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);
    }

    protected function hostWithBlock(): RecordingRootAgent
    {
        $host = $this->make(RecordingRootAgent::class);
        $this->bindProfileOverride($host, $this->profileVariant($host->profile(), [
            'tools' => [...$host->profile()->tools, 'block'],
        ]));

        return $host;
    }

    /** @return list<array<string, mixed>> */
    protected function failedTaskFinishEvents(RecordingRootAgent $host, ?string $from = null): array
    {
        return array_values(array_filter(
            $host->deliveredTaskEvents(),
            static fn (array $event): bool => ($event['signal'] ?? '') === 'task_finish'
                && ($event['status'] ?? '') === 'failed'
                && ($from === null || ($event['thread_id'] ?? '') === $from),
        ));
    }

    protected function childWithBlock(RecordingRootAgent $host): Agent
    {
        $child = $this->spawnDelegated($host, 'lite');
        $this->bindProfileOverride($child, $this->profileVariant($child->profile(), [
            'tools' => [...$child->profile()->tools, 'block'],
        ]));

        return $child;
    }

    public function testCancelDuringTaskingDeliverDoesNotDeliverTaskFinishFailedToParent(): void
    {
        $stub = new StubLlm();
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->hostWithBlock();
        $child = $this->childWithBlock($host);
        $this->openTaskForTest($child, 'kill-during-deliver');

        $key = $this->startBlockedRun($child, $stub, 'work');
        try {
            $child->cancel();
        } finally {
            $this->releaseBlockedRun($key);
        }
        $this->pumpUntil(fn (): bool => !$this->isTaskOpen($child));

        $this->assertSame([], $this->failedTaskFinishEvents($host, $this->leafOf($child)));
        $this->assertFalse($this->isTaskOpen($child));
        $finish = $host->lastDeliveredTaskEvent('task_finish');
        $this->assertNotNull($finish);
        $this->assertSame('stopped', $finish['status'] ?? null);
    }

    public function testCancelSubagentDuringTaskingDeliverDoesNotDeliverTaskFinishFailedToParent(): void
    {
        $stub = new StubLlm();
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->hostWithBlock();
        $child = $this->childWithBlock($host);
        $leaf = $this->leafOf($child);
        $this->openTaskForTest($child, 'kill-subagent-during-deliver');

        $key = $this->startBlockedRun($child, $stub, 'work');
        try {
            $child->cancel();
        } finally {
            $this->releaseBlockedRun($key);
        }
        $this->pumpUntil(fn (): bool => !isset($this->liveChildren($host)[$leaf]));

        $this->assertSame([], $this->failedTaskFinishEvents($host, $leaf));
        $this->assertArrayNotHasKey($leaf, $this->liveChildren($host));
        $this->assertFalse($this->isTaskOpen($child));
    }

    public function testTaskingDeliveryFailureStillDeliversTaskFinishFailedToParent(): void
    {
        $stub = new StubLlm();
        $stub->throwOnce = new RuntimeException('stub LLM down');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->hostWithBlock();
        $child = $this->spawnDelegated($host, 'lite');
        $leaf = $this->leafOf($child);
        $this->openTaskForTest($child, 'real-failure');

        $this->deliverForTest($child, UserMessage::of('work'));
        $this->pumpUntil(fn (): bool => $host->lastDeliveredTaskEvent('task_finish') !== null);

        $finish = $host->lastDeliveredTaskEvent('task_finish');
        $this->assertSame('failed', $finish['status'] ?? null);
        $this->assertSame($leaf, $finish['thread_id'] ?? null);
        $this->assertStringContainsString('stub LLM down', (string) ($finish['body'] ?? ''));
        $this->assertFalse($this->isTaskOpen($child));
        $turn = $child->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Suspended, $turn->status);
    }

    public function testConcurrentSyncRunsQueueIndependently(): void
    {
        $stub = new StubLlm();
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->hostWithBlock();
        $key = 'concurrent_' . bin2hex(random_bytes(4));
        BlockingTool::arm($key);
        $stub->responses[] = StubLlm::assistantWithToolCall('block', ['key' => $key], 'call_' . $key);
        $stub->responses[] = StubLlm::assistant('after block');
        $stub->responses[] = StubLlm::assistant('second done');

        $firstDone = new Channel(1);
        Coroutine::create(function () use ($host, $firstDone): void {
            $host->run(Envelope::of('first', $host->profile()->model));
            $firstDone->push(true);
        });

        BlockingTool::waitUntilBlocked($key);

        $secondDone = new Channel(1);
        $secondTurn = null;
        Coroutine::create(function () use ($host, &$secondTurn, $secondDone): void {
            $secondTurn = $host->run(Envelope::of('second', $host->profile()->model));
            $secondDone->push(true);
        });

        $this->assertFalse($secondDone->pop(0.05));

        BlockingTool::release($key);
        $this->assertTrue($firstDone->pop(2.0));
        $this->assertTrue($secondDone->pop(2.0));
        $this->assertSame('second done', $secondTurn?->lastCompletion()?->output?->content);
    }

    public function testCancelDispatchedAfterSuccessfulRunDoesNotOverrideOutcome(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('done');
        $this->container->set(LlmInterface::class, $stub);

        $agent = null;
        $cancelTrigger = null;
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$agent, &$cancelTrigger): object {
                if ($event instanceof DeliverySettled) {
                    $cancelTrigger?->push(true);
                }

                return $event;
            },
        );
        $this->container->replace(EventDispatcherInterface::class, $dispatcher);
        $agent = $this->make(Agent::class);
        $cancelTrigger = $this->armExternalCancel($agent);

        $turn = $this->runForTest($agent, UserMessage::of('go'));

        $this->assertSame('done', $turn->lastCompletion()?->output?->content);
        $this->assertNull($this->interruptCompletion($agent, 'cancelled'));
        $this->pumpUntilCancelDrained($agent);
    }

    public function testDeliveryStartedListenerFailureAbortsCurrentTurn(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            static function (object $event): object {
                if ($event instanceof DeliveryStarted) {
                    throw new RuntimeException('run listener failed');
                }

                return $event;
            },
        );
        $this->container->replace(EventDispatcherInterface::class, $dispatcher);
        $agent = $this->make(Agent::class);

        $error = $this->tryRun($agent, UserMessage::of('go'));

        $this->assertNotNull($error);
        $this->assertStringContainsString('run listener failed', $error->getMessage());

        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Aborted, $turn->status);
        $this->assertNull($turn->interrupt);
        $output = $turn->lastCompletion()->output;
        $this->assertInstanceOf(AssistantAbortSignal::class, $output);
        $this->assertSame(AssistantAbortSignal::KIND_EXCEPTION, $output->kind);
    }

    public function testFinishTaskUnregistersWhenParentDeliverFails(): void
    {
        $host = $this->make(ThrowOnTaskFinishDeliverAgent::class);
        $child = $this->spawnDelegated($host, 'lite');
        $leaf = $this->leafOf($child);
        $this->openTaskForTest($child, 'finish-deliver-fail');

        try {
            $this->finishTaskForTest($child, 'done', 'completed');
            $this->fail('finishTask must propagate parent deliver failure.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('Parent inbox rejected task_finish', $e->getMessage());
        }

        $this->assertArrayNotHasKey($leaf, $this->liveChildren($host));
        $this->assertFalse($this->isTaskOpen($child));
    }

    public function testModelStepFailureLandsInterruptOnCurrentTurn(): void
    {
        $stub = new StubLlm();
        $stub->throwOnce = new RuntimeException('stub LLM down');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->testAgent();
        $priorRunId = 'prior-turn';
        $c_prior_step = Completion::of(UserMessage::of('first'), new AssistantMessage('ok'));
        $c_prior_step->completeId = 'prior-step';
        $c_prior_step->step = 1;
        $this->threadAppend($host->thread(), $c_prior_step);

        $error = $this->tryRun($host, UserMessage::of('second'));
        $this->assertNotNull($error);
        $this->assertStringContainsString('stub LLM down', $error->getMessage());

        $turn = $host->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Suspended, $turn->status);
        $this->assertNull($this->interruptCompletion($host, 'exception'));
    }

    public function testHttpStatusFailureLandsInterruptWhenDeliveryStarted(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo');
        $stub->responses[] = static function (): never {
            throw HttpStatusException::of('rate limited', ['status_code' => 429]);
        };

        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'echo-ok'),
            ],
        ]));

        $host = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['echo']),
        ]);

        $error = $this->tryRun($host, UserMessage::of('go'));
        $this->assertNotNull($error);
        $this->assertInstanceOf(HttpStatusException::class, $error);

        $turn = $host->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Suspended, $turn->status);
        $this->assertNull($this->interruptCompletion($host, 'exception'));
    }

    public function testDelegatedCancelDuringStep1StreamNotifiesParentOnly(): void
    {
        $stub = new StubLlm();
        $stub->streamDeltas = ['a', 'b', 'c'];
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->hostWithBlock();
        $child = $this->spawnDelegated($host, 'lite');
        $leaf = $this->leafOf($child);
        $this->openTaskForTest($child, 'cancel-stream');

        $before = count($this->threadCompletions($child));

        $cancelTrigger = $this->armExternalCancel($child);
        $stub->onStreamDelta = static function (int $index) use ($cancelTrigger): void {
            if ($index === 1) {
                $cancelTrigger->push(true);
            }
        };
        $this->deliverSubForTest($child, UserMessage::of('stream'));
        $this->pumpUntilCancelDrained($child);

        $finish = $host->lastDeliveredTaskEvent('task_finish');
        $this->assertSame('stopped', $finish['status'] ?? null);
        $this->assertSame($leaf, $finish['thread_id'] ?? null);
        $this->assertGreaterThan($before, count($this->threadCompletions($child)));
    }

    public function testDelegatedHttpStatusDeliversTaskFinishExceptionToParent(): void
    {
        $stub = new StubLlm();
        $stub->throwOnce = HttpStatusException::of('rate limited', ['status_code' => 429]);
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->hostWithBlock();
        $child = $this->spawnDelegated($host, 'lite');
        $leaf = $this->leafOf($child);
        $this->openTaskForTest($child, 'http-failure');

        $this->deliverForTest($child, UserMessage::of('work'));
        $this->pumpUntil(fn (): bool => $host->lastDeliveredTaskEvent('task_finish') !== null);

        $finish = $host->lastDeliveredTaskEvent('task_finish');
        $this->assertSame('failed', $finish['status'] ?? null);
        $this->assertSame($leaf, $finish['thread_id'] ?? null);
        $this->assertSame('LLM API rate limit exceeded.', $finish['body'] ?? null);
        $this->assertFalse($this->isTaskOpen($child));
        $turn = $child->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Suspended, $turn->status);
    }

    public function testIsIdleOnlyWhileWorkerBlocksOnEmptyInbox(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('done');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->hostWithBlock();
        $this->pumpUntil(fn (): bool => $host->isIdle());

        $key = $this->startBlockedRun($host, $stub, 'hold');
        $this->assertFalse($host->isIdle());

        $this->releaseBlockedRun($key);
        $this->pumpUntil(fn (): bool => $host->isIdle());
    }

    public function testIsIdleStaysFalseWhileChildIsBusy(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('done');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->hostWithBlock();
        $this->pumpUntil(fn (): bool => $host->isIdle());
        $child = $this->spawnDelegated($host, 'lite');
        $this->bindProfileOverride($child, $this->profileVariant($child->profile(), [
            'tools' => [...$child->profile()->tools, 'block'],
        ]));
        $this->pumpUntil(fn (): bool => $child->isIdle());
        $this->assertTrue($host->isIdle());

        $key = 'block_' . bin2hex(random_bytes(8));
        BlockingTool::arm($key);
        array_unshift($stub->responses, StubLlm::assistantWithToolCall(
            'block',
            ['key' => $key],
            'call_' . $key,
        ));
        $child->deliver(Envelope::of(
            UserMessage::of('hold child'),
            $this->modelForTest($child),
        ));
        $this->pumpUntil(static function () use ($key): bool {
            $channel = BlockingTool::enteredChannel($key);

            return $channel !== null && $channel->length() > 0;
        });
        $this->assertFalse($host->isIdle());

        BlockingTool::release($key);
        $this->pumpUntil(fn (): bool => $host->isIdle());
    }

    public function testIdleCancelDoesNotAbortNextRun(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('hello');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->hostWithBlock();

        $host->cancel();
        $turn = $this->runForTest($host, UserMessage::of('queued'));

        $this->assertSame('hello', trim((string) ($turn->lastCompletion()?->output?->content ?? '')));
        $this->assertGreaterThan(0, $stub->streamCalls);
        $this->assertNull($this->interruptCompletion($host, 'cancelled'));
    }

    public function testCancelFromDeliveryStartedStopsBeforeModelStep(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('must not run');
        $this->container->set(LlmInterface::class, $stub);

        $agent = null;
        $cancelTrigger = null;
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$cancelTrigger): object {
                if ($event instanceof DeliveryStarted) {
                    $cancelTrigger?->push(true);
                }

                return $event;
            },
        );
        $this->container->replace(EventDispatcherInterface::class, $dispatcher);
        $agent = $this->make(Agent::class);
        $cancelTrigger = $this->armExternalCancel($agent);

        $error = $this->tryRun($agent, UserMessage::of('go'));

        $this->assertInstanceOf(RunCancelledException::class, $error);
        $this->assertSame(0, $stub->streamCalls);
        $this->assertNotNull($this->interruptCompletion($agent, 'cancelled'));
        $this->pumpUntilCancelDrained($agent);
    }

    public function testShutdownDrainsQueuedSyncRunWithStoppedOutcome(): void
    {
        $stub = new StubLlm();
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->hostWithBlock();

        $holdKey = $this->startBlockedRun($host, $stub, 'hold worker');
        $outcome = $this->enqueueSyncRunForTest($host, UserMessage::of('queued'));

        $shutdownDone = new Channel(1);
        Coroutine::create(function () use ($host, $shutdownDone): void {
            $host->shutdown();
            $shutdownDone->push(true);
        });
        $this->assertTrue($shutdownDone->pop(2.0), 'shutdown hung draining queued sync run');

        $error = $outcome->pop(2.0);
        $this->assertInstanceOf(RunCancelledException::class, $error);

        BlockingTool::release($holdKey);
    }

    public function testDelegatedCancelDeliversTaskFinishStoppedToParent(): void
    {
        $stub = new StubLlm();
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->hostWithBlock();
        $child = $this->childWithBlock($host);
        $leaf = $this->leafOf($child);
        $this->openTaskForTest($child, 'cancel-during-deliver');

        $key = $this->startBlockedRun($child, $stub, 'work');
        try {
            $child->cancel();
        } finally {
            $this->releaseBlockedRun($key);
        }

        $this->pumpUntil(fn (): bool => $host->lastDeliveredTaskEvent('task_finish') !== null);
        $finish = $host->lastDeliveredTaskEvent('task_finish');
        $this->assertSame('stopped', $finish['status'] ?? null);
        $this->assertSame($leaf, $finish['thread_id'] ?? null);
        $this->assertFalse($this->isTaskOpen($child));
        $this->assertArrayNotHasKey($leaf, $this->liveChildren($host));
    }

    public function testCancelBubblesToStopQueuedDelivery(): void
    {
        $stub = new StubLlm();
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->hostWithBlock();

        $stub->responses[] = StubLlm::assistant('queued completed');
        $key = $this->startBlockedRun($host, $stub, 'hold worker');
        $host->deliver(Envelope::of('queued', $host->profile()->model));
        $host->cancel();
        $this->releaseBlockedRun($key);
        $this->pumpUntilCancelDrained($host);

        $this->assertNotNull($this->interruptCompletion($host, 'cancelled'));
        $this->assertNotSame('queued completed', $this->lastAssistantContent($host));
        $this->assertSame(1, $stub->streamCalls);
    }

    public function testCancelDuringStreamAbortsRun(): void
    {
        $stub = new StubLlm();
        $stub->streamDeltas = ['a', 'b', 'c', 'd', 'e'];
        $stub->responses[] = StubLlm::assistant('should not land');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->hostWithBlock();

        $cancelTrigger = $this->armExternalCancel($host);
        $stub->onStreamDelta = static function (int $index) use ($cancelTrigger): void {
            if ($index === 1) {
                $cancelTrigger->push(true);
            }
        };
        $host->deliver(Envelope::of('stream', $host->profile()->model));
        $this->pumpUntil(fn (): bool => $stub->shouldAbortPolls >= 3);
        $this->pumpUntilCancelDrained($host);

        $this->assertSame(1, $stub->streamCalls);
        $this->assertGreaterThanOrEqual(3, $stub->shouldAbortPolls);
        $this->assertNull($this->interruptCompletion($host, 'cancelled'));
        $this->assertSame(TurnStatus::Completed, $host->thread()->lastTurn()->status);
        $this->assertSame('ab', $this->lastAssistantContent($host));
    }

    public function testParentCancelStopsBackgroundChild(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('background done');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->hostWithBlock();
        $child = $this->childWithBlock($host);
        $leaf = $this->leafOf($child);
        $this->openTaskForTest($child, 'background-worker');

        $key = $this->startBlockedRun($child, $stub, 'work');
        try {
            $host->cancel();
        } finally {
            $this->releaseBlockedRun($key);
        }

        $this->assertArrayNotHasKey($leaf, $this->liveChildren($host));
        $this->assertNotSame('background done', $this->lastAssistantContent($child));
        $this->assertSame(1, $stub->streamCalls);
        $finish = $host->lastDeliveredTaskEvent('task_finish');
        $this->assertNotNull($finish);
        $this->assertSame('stopped', $finish['status'] ?? null);
    }

    public function testParentCancelStopsSyncAsToolChild(): void
    {
        $stub = new StubLlm();
        $key = 'sync-cancel';
        BlockingTool::arm($key);
        $stub->responses[] = StubLlm::assistantWithToolCall('agent_lite', ['message' => 'go'], 'call_as');
        $stub->responses[] = StubLlm::assistantWithToolCall('block', ['key' => $key], 'call_block');
        $stub->responses[] = StubLlm::assistant('child should not continue');
        $stub->responses[] = StubLlm::assistant('parent should not continue');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->hostWithBlock();
        $profiles = $this->container->get(\Switon\Agency\ProfilesInterface::class);
        $this->setTestProfile($this->profileWithTools($profiles->get('lite'), 'block'));
        $this->bindProfileOverride($host, $this->withAsTool($host->profile(), 'agent_lite'));

        $host->deliver(Envelope::of('go', $host->profile()->model));
        BlockingTool::waitUntilBlocked($key);
        try {
            $host->cancel();
        } finally {
            $this->releaseBlockedRun($key);
        }
        $this->pumpUntilCancelDrained($host);

        $this->assertNotNull($this->interruptCompletion($host, 'cancelled'));
        $this->assertNotSame('parent should not continue', $this->lastAssistantContent($host));
        $this->assertNotSame('child should not continue', $this->lastAssistantContent($host));
        $this->assertSame(2, $stub->streamCalls);
    }

    public function testCancelDuringToolExecutionAbortsRemainingTools(): void
    {
        $stub = new StubLlm();
        $laterRan = 0;
        /** @var Agent|null $agentRef */
        $agentRef = null;

        $completion = Completion::of(UserMessage::of(''), new AssistantMessage('', [
                new ToolCall('c1', 'block', ['key' => 'cancel-tool']),
                new ToolCall('c2', 'later', []),
            ]));
        $completion->completeId = 'chatcmpl-cancel-batch';
        $completion->finishReason = 'tool_calls';
        $completion->usage = new TokenUsage();
        $stub->responses[] = $completion;
        $stub->responses[] = StubLlm::assistant('should not continue');
        $this->container->set(LlmInterface::class, $stub);

        $toolsRegistry = $this->make(Tools::class, [
            'tools' => [
                'block' => BlockingTool::class,
                'later' => StubTool::config(
                    'later',
                    'Later',
                    static function () use (&$laterRan): string {
                        $laterRan++;

                        return 'later-ok';
                    },
                ),
                ...$this->builtinToolConfig(),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);
        $this->container->replace(ToolsInterface::class, $toolsRegistry);

        $host = $this->hostWithBlock();
        $agentRef = $host;

        BlockingTool::arm('cancel-tool');
        $host->deliver(Envelope::of('go', $host->profile()->model));
        BlockingTool::waitUntilBlocked('cancel-tool');

        $agentRef->cancel();
        BlockingTool::release('cancel-tool');
        $this->pumpUntil(fn (): bool => $this->interruptCompletion($host, 'cancelled') !== null);

        $this->assertSame(0, $laterRan);
        $this->assertNotNull($this->interruptCompletion($host, 'cancelled'));
        // Open tools are closed on stop (interrupt ToolMessage), then the stop tip lands.
        $this->assertNotEmpty(array_filter(
            $this->threadMessages($host),
            static fn ($message): bool => $message instanceof ToolMessage,
        ));
    }

    public function testDeliverRaisesWhenInboxFull(): void
    {
        $stub = new StubLlm();
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->make(FastInboxAgent::class, [
            'inboxSize' => 1,
        ]);
        $this->bindProfileOverride($host, $this->profileVariant($host->profile(), [
            'tools' => [...$host->profile()->tools, 'block'],
        ]));

        $key = $this->startBlockedRun($host, $stub, 'hold worker');
        $host->deliver(Envelope::of('queued', $host->profile()->model));

        $error = null;
        try {
            $host->deliver(Envelope::of('overflow', $host->profile()->model));
        } catch (DeliveryException $e) {
            $error = $e;
        }

        $this->assertNotNull($error);
        $this->assertSame(DeliveryException::REASON_FULL, $error->reason);
        $this->assertStringContainsString('Timed out enqueueing delivery (inbox full)', $error->getMessage());

        BlockingTool::release($key);
    }
}
