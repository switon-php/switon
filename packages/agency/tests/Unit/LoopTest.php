<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use Switon\Ai\ToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Agent;
use Switon\Agency\Loop;
use Switon\Agency\Exception\RunCancelledException;
use Switon\Agency\ProfilesInterface;
use Switon\Ai\TurnStatus;
use Switon\Agency\Tests\Fixtures\RecordingRootAgent;
use Switon\Agency\Tests\Fixtures\BlockingTool;
use Switon\Agency\Tests\Fixtures\NoReplyStubTool;
use Switon\Agency\Tests\Fixtures\StubTool;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Core\Json;
use Switon\Core\Strings;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;
use Switon\Ai\Signal\AssistantAbortSignal;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\FoldedMessage;
use Switon\Thread\Event\CompletionAppended;
use Switon\Thread\ExcerptExtension;
use Switon\Ai\LlmInterface;
use Switon\Ai\Completion;
use Switon\Ai\Thread;
use Switon\Ai\Message;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Turn;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Exception\InvalidToolArgumentsException;
use Switon\Ai\Exception\ToolAbortedException;
use Switon\Tooling\Exception\ArgumentBindException;
use Switon\Ai\ToolCall;
use Switon\Ai\TokenUsage;
use Switon\Ai\Event\TurnSuspended;
use Switon\Ai\Suspension\RetryInterrupt;
use Switon\Ai\Signal\AssistantSignal;
use Switon\Ai\Suspension\RetryContinuation;
use ReflectionProperty;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(Agent::class)]
#[Group('coroutine')]
class LoopTest extends TestCase
{
    protected function invokeAgentRun(Agent $agent, Message $message): void
    {
        $this->runDeliveryForTest($agent, $message);
    }

    protected function seedConversation(Agent $agent, Thread $thread): void
    {
        $thread = $this->withAgentStorageDir($agent, $thread);
        $property = new ReflectionProperty(Agent::class, 'thread');
        $property->setValue($agent, $thread);
        $this->threadsImpl()->replaceConversation($thread);
        if ($thread->turns !== [] && !$thread->lastTurn()->isSettled()) {
            $this->threadsImpl()->saveTurn($thread);
        }
    }

    public function testDeliverCompletes(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('done');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, [
                    ]);

        $this->runForTest($agent, UserMessage::of('go'));

        $this->assertSame('done', $this->lastAssistantContent($agent));
        $this->assertCount(1, $stub->requests);
    }

    public function testLandedToolCallDispatchesCompletionAppended(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo', ['text' => 'hi']);
        $stub->responses[] = StubLlm::assistant('done');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'echo-ok'),
            ],
        ]));

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['echo']),
        ]);
        $this->runForTest($agent, UserMessage::of('go'));

        $events = $this->container->get(\Psr\EventDispatcher\EventDispatcherInterface::class)
            ->getEventsByClass(CompletionAppended::class);
        $this->assertCount(1, $events);
        $this->assertSame($agent->thread(), $events[0]->thread);
    }

    public function testCompactionRunsBeforeFirstModelStep(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('done');
        $this->container->set(LlmInterface::class, $stub);
        $this->extensionRegistry->set($this->make(ExcerptExtension::class));

        $agent = $this->make(Agent::class, []);
        $agent->thread()->turns[] = Turn::of(
            'older',
            [Completion::of(UserMessage::of('way back'), new AssistantMessage('ancient'))],
            TurnStatus::Completed,
        );
        $earlier = Completion::of(UserMessage::of('earlier'), new AssistantMessage('old reply'));
        $earlier->usage = new TokenUsage(input: 120_000);
        $agent->thread()->turns[] = Turn::of('old', [$earlier], TurnStatus::Completed);
        $this->runForTest($agent, UserMessage::of('go'));

        $folded = false;
        foreach ($stub->requests[0] ?? [] as $message) {
            if ($message instanceof FoldedMessage) {
                $folded = true;
            }
        }
        $this->assertTrue($folded, 'the fold head reaches the first model step');
    }

    public function testDeliverRetriesOnceAfterInvalidToolArgumentsThenSucceeds(): void
    {
        $stub = new StubLlm();
        $stub->throwOnce = new InvalidToolArgumentsException(
            'Tool "{name}" arguments must decode to a JSON object.',
            new ToolCall('call_1', 'echo'),
            '{"x"',
            ['name' => 'echo'],
        );
        $stub->responses[] = StubLlm::assistant('fixed');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, [
                    ]);

        $this->runForTest($agent, UserMessage::of('go'));

        $this->assertSame('fixed', $this->lastAssistantContent($agent));
        $this->assertCount(2, $stub->requests);
        $this->assertInstanceOf(AssistantMessage::class, $agent->thread()->turns[0]->completions[0]->output);
        $this->assertNotInstanceOf(AssistantSignal::class, $agent->thread()->turns[0]->completions[0]->output);
    }

    public function testDeliverSuspendsAfterInvalidToolArgumentsRetryExhausted(): void
    {
        $exception = static fn (): InvalidToolArgumentsException => new InvalidToolArgumentsException(
            'Tool "{name}" arguments must decode to a JSON object.',
            new ToolCall('call_1', 'echo'),
            '{"x"',
            ['name' => 'echo'],
        );
        $stub = new StubLlm();
        $stub->throwQueue = [$exception(), $exception()];
        $stub->responses[] = StubLlm::assistant('should-not-run');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, [
                    ]);

        try {
            $this->runForTest($agent, UserMessage::of('go'));
            $this->fail('Expected InvalidToolArgumentsException');
        } catch (InvalidToolArgumentsException) {
        }

        $this->assertCount(2, $stub->requests);
        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Suspended, $turn->status);
        $this->assertSame('go', $turn->completions[0]->input->wire()[0]['text'] ?? null);
        $this->assertSame(1, $turn->completions[0]->step);
        $events = $this->container->get(\Psr\EventDispatcher\EventDispatcherInterface::class)
            ->getEventsByClass(TurnSuspended::class);
        $this->assertNotEmpty($events);
        $interrupt = $events[array_key_last($events)]->interrupt;
        $this->assertInstanceOf(RetryInterrupt::class, $interrupt);
        $this->assertSame(RetryInterrupt::REASON_TOOL_ARGS_INVALID, $interrupt->reason);
    }

    public function testRetryMailReplaysSuspendedStepLikeFreshLlmCall(): void
    {
        $exception = static fn (): InvalidToolArgumentsException => new InvalidToolArgumentsException(
            'Tool "{name}" arguments must decode to a JSON object.',
            new ToolCall('call_1', 'echo'),
            '{"x"',
            ['name' => 'echo'],
        );
        $stub = new StubLlm();
        $stub->throwQueue = [$exception(), $exception()];
        $stub->responses[] = StubLlm::assistant('recovered');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, []);

        try {
            $this->runForTest($agent, UserMessage::of('go'));
            $this->fail('Expected InvalidToolArgumentsException');
        } catch (InvalidToolArgumentsException) {
        }

        $this->runForTest($agent, RetryContinuation::of());

        $this->assertSame('recovered', $this->lastAssistantContent($agent));
        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Completed, $turn->status);
        $this->assertCount(3, $stub->requests);
    }

    public function testNewUserMailClosesSuspendedTurnBeforeStartingFresh(): void
    {
        $exception = static fn (): InvalidToolArgumentsException => new InvalidToolArgumentsException(
            'Tool "{name}" arguments must decode to a JSON object.',
            new ToolCall('call_1', 'echo'),
            '{"x"',
            ['name' => 'echo'],
        );
        $stub = new StubLlm();
        $stub->throwQueue = [$exception(), $exception()];
        $stub->responses[] = StubLlm::assistant('fresh');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, []);

        try {
            $this->runForTest($agent, UserMessage::of('go'));
            $this->fail('Expected InvalidToolArgumentsException');
        } catch (InvalidToolArgumentsException) {
        }

        $suspendedId = $agent->thread()->lastTurn()?->turnId;
        $this->assertNotNull($suspendedId);

        $this->runForTest($agent, UserMessage::of('new'));

        $this->assertSame('fresh', $this->lastAssistantContent($agent));
        $turns = $agent->thread()->turns;
        $this->assertGreaterThanOrEqual(2, count($turns));
        $this->assertSame(TurnStatus::Aborted, $turns[0]->status);
        $this->assertSame($suspendedId, $turns[0]->turnId);
        $this->assertSame(TurnStatus::Completed, $turns[array_key_last($turns)]->status);
    }

    public function testDeliverContinuesAfterUnknownToolViaToolResult(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('nope', [], 'call_nope', null);
        $stub->responses[] = StubLlm::assistant('fixed');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, [
                    ]);

        $this->runForTest($agent, UserMessage::of('go'));

        $this->assertSame('fixed', $this->lastAssistantContent($agent));
        $this->assertCount(2, $stub->requests);
        $this->assertTrue($this->requestIncludesToolResultNamed($stub->requests[1], 'nope'));
        $this->assertStringContainsString('not allowed', $this->toolResultContentNamed($stub->requests[1], 'nope'));
        $this->assertSame('go', $this->firstUserCompletionInput($agent));
    }

    public function testRunLandsToolResultsWhenNextLlmStepThrows(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo', ['text' => 'ping']);
        $stub->responses[] = static function (): never {
            throw new RuntimeException('LLM down');
        };
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'echo-ok'),
            ],
        ]));

        $agent = $this->make(Agent::class, [
                        'profile' => $this->testProfile(tools: ['echo']),
            ]);

        try {
            $this->runForTest($agent, UserMessage::of('go'));
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException) {
        }

        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Suspended, $turn->status);
        $this->assertNull($this->interruptCompletion($agent, 'exception'));
    }

    public function testDeliverRetriesOnceAfterSchemaViolationThenSucceeds(): void
    {
        $stub = new StubLlm();
        // Missing required `message` — bind failure, retry carries the field error (not a tool result).
        $stub->responses[] = StubLlm::assistantWithToolCall(
            'task_deliver',
            ['.caption' => 'Assign'],
            'call_bad',
            null,
        );
        $stub->responses[] = StubLlm::assistant('assigned');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(RecordingRootAgent::class, []);

        $this->runForTest($agent, UserMessage::of('go'));

        $this->assertSame('assigned', $this->lastAssistantContent($agent));
        $this->assertCount(2, $stub->requests);
        $this->assertFalse($this->requestIncludesToolResultNamed($stub->requests[1], 'task_deliver'));
        $this->assertFalse($this->requestIncludesWireContaining($stub->requests[0], 'message required'));
        $this->assertTrue($this->requestIncludesWireContaining($stub->requests[1], 'message required'));
        $this->assertSame('go', $this->firstUserCompletionInput($agent));
    }

    public function testInvalidValueRetriesWithTheBindError(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall(
            'sleep',
            ['seconds' => 0],
            'call_bad',
            null,
        );
        $stub->responses[] = StubLlm::assistant('stopped');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['sleep']),
        ]);

        $this->runForTest($agent, UserMessage::of('go'));

        $this->assertSame('stopped', $this->lastAssistantContent($agent));
        $this->assertCount(2, $stub->requests);
        $this->assertFalse($this->requestIncludesToolResultNamed($stub->requests[1], 'sleep'));
        $this->assertTrue($this->requestIncludesWireContaining(
            $stub->requests[1],
            'seconds must be greater than 0',
        ));
    }

    public function testDeliverSuspendsAfterSchemaViolationRetryExhausted(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall(
            'task_deliver',
            ['.caption' => 'Assign'],
            'call_bad_1',
            null,
        );
        $stub->responses[] = StubLlm::assistantWithToolCall(
            'task_deliver',
            ['.caption' => 'Assign'],
            'call_bad_2',
            null,
        );
        $stub->responses[] = StubLlm::assistant('should-not-run');
        $this->container->set(LlmInterface::class, $stub);

        // Same tool surface as the successful-retry case (must expose task_deliver).
        $agent = $this->make(RecordingRootAgent::class, []);

        try {
            $this->runForTest($agent, UserMessage::of('go'));
            $this->fail('Expected ArgumentBindException');
        } catch (ArgumentBindException) {
        }

        $this->assertCount(2, $stub->requests);
        $this->assertTrue($this->requestIncludesWireContaining($stub->requests[1], 'message required'));
        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Suspended, $turn->status);
        $this->assertNull($this->interruptCompletion($agent, 'exception'));
        $tip = $turn->lastCompletion();
        $this->assertNotNull($tip);
        $this->assertInstanceOf(AssistantMessage::class, $tip->output);
        $events = $this->container->get(\Psr\EventDispatcher\EventDispatcherInterface::class)
            ->getEventsByClass(TurnSuspended::class);
        $this->assertNotEmpty($events);
        $interrupt = $events[array_key_last($events)]->interrupt;
        $this->assertInstanceOf(RetryInterrupt::class, $interrupt);
        $this->assertSame(RetryInterrupt::REASON_TOOL_ARGS_INVALID, $interrupt->reason);
    }

    public function testRetryMailAfterSchemaViolationResetsHungTipNotFakeClosure(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall(
            'task_deliver',
            ['.caption' => 'Assign'],
            'call_bad_retry',
            null,
        );
        $stub->responses[] = StubLlm::assistantWithToolCall(
            'task_deliver',
            ['.caption' => 'Assign'],
            'call_bad_retry_2',
            null,
        );
        $stub->responses[] = StubLlm::assistant('recovered-after-schema');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(RecordingRootAgent::class, []);

        try {
            $this->runForTest($agent, UserMessage::of('go'));
            $this->fail('Expected ArgumentBindException');
        } catch (ArgumentBindException) {
        }

        $this->assertNull($this->interruptCompletion($agent, 'exception'));
        $hungId = $agent->thread()->lastCompletion()?->completeId;
        $this->assertNotNull($hungId);

        $this->runForTest($agent, RetryContinuation::of());

        $this->assertSame('recovered-after-schema', $this->lastAssistantContent($agent));
        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Completed, $turn->status);
        $this->assertNull($this->interruptCompletion($agent, 'exception'));
    }

    public function testDeliverSuspendsOnContextOverflow(): void
    {
        $stub = new StubLlm();
        $stub->throwQueue = [\Switon\Ai\Exception\ContextLengthExceededException::of('window full')];
        $stub->responses[] = StubLlm::assistant('should-not-run');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, []);

        try {
            $this->runForTest($agent, UserMessage::of('go'));
            $this->fail('Expected ContextLengthExceededException');
        } catch (\Switon\Ai\Exception\ContextLengthExceededException) {
        }

        $this->assertCount(1, $stub->requests);
        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Suspended, $turn->status);
        $this->assertInstanceOf(RetryInterrupt::class, $turn->interrupt);
        $this->assertSame(RetryInterrupt::REASON_CONTEXT_OVERFLOW, $turn->interrupt->reason);
    }

    public function testPartialToolBatchInfrastructureFailureAbortsWithoutRetry(): void
    {
        $this->extensionRegistry->set(
            new \Switon\Agency\Tests\Fixtures\ThrowingBeforeTool(allowCount: 1, message: 'tool boom'),
        );

        $stub = new StubLlm();
        $twoCalls = Completion::of(UserMessage::of(''), new AssistantMessage('', [
            new ToolCall('call_a', 'echo', ['text' => 'a', '.caption' => 'a']),
            new ToolCall('call_b', 'echo', ['text' => 'b', '.caption' => 'b']),
        ]));
        $twoCalls->completeId = 'stub-two-tools';
        $twoCalls->finishReason = 'tool_calls';
        $twoCalls->usage = new TokenUsage();
        $stub->responses[] = $twoCalls;
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'echo-ok'),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]));

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['echo']),
        ]);

        try {
            $this->runForTest($agent, UserMessage::of('go'));
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tool boom', $e->getMessage());
        }

        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Aborted, $turn->status);
        $this->assertNull($turn->interrupt);
        $tip = $turn->lastCompletion();
        $this->assertNotNull($tip);
        $this->assertInstanceOf(ToolMessage::class, $tip->input);
        $this->assertInstanceOf(AssistantAbortSignal::class, $tip->output);
        $this->assertSame(AssistantAbortSignal::KIND_EXCEPTION, $tip->output->kind);
        $results = $tip->input->results;
        $this->assertSame(['call_a', 'call_b'], array_map(static fn ($r) => $r->id, $results));
        $this->assertSame('echo-ok', $results[0]->content);
        $this->assertInstanceOf(ToolAbortedException::class, $results[1]->content);
    }

    public function testDeliverLandNoReplyToolResultInConversation(): void
    {
        $stub = new StubLlm();
        $completion4 = Completion::of(UserMessage::of(''), new AssistantMessage('done', [
                new ToolCall('call_quiet', 'quiet', ['.caption' => 'Quiet']),
            ]));
        $completion4->completeId = 'chatcmpl-quiet';
        $completion4->finishReason = 'tool_calls';
        $completion4->usage = new TokenUsage();
        $stub->responses[] = $completion4;
        $stub->responses[] = StubLlm::assistant('after quiet');
        $this->container->set(LlmInterface::class, $stub);

        $toolsRegistry = $this->make(Tools::class, [
            'tools' => [
                'quiet' => [
                    'class' => NoReplyStubTool::class,
                    'name' => 'quiet',
                    'handler' => static fn (): string => 'hidden',
                ],
            ],
        ]);
        $this->container->replace(ToolsInterface::class, $toolsRegistry);

        $agent = $this->make(Agent::class, [
                        'profile' => $this->testProfile(tools: ['quiet']),
            ]);

        $this->runForTest($agent, UserMessage::of('go'));

        $this->assertSame('after quiet', $this->lastAssistantContent($agent));
        $this->assertCount(2, $stub->requests);
        $toolMessages = array_values(array_filter(
            $this->threadMessages($agent),
            static fn (Message $message): bool => $message instanceof ToolMessage,
        ));
        $this->assertCount(1, $toolMessages);
        $this->assertSame('quiet', $toolMessages[0]->results[0]->name);
    }

    public function testMaxStepsOneSynthesizesWithoutToolLoop(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('single-step done');
        $this->container->set(LlmInterface::class, $stub);

        $toolsRegistry = $this->make(Tools::class, [
            'tools' => [
                'loop' => StubTool::config('loop', 'Loop', static fn (): string => 'again'),
            ],
        ]);
        $this->container->replace(ToolsInterface::class, $toolsRegistry);

        $agent = $this->make(Agent::class, [
                        'profile' => $this->testProfile(tools: ['loop'], maxSteps: 1),
            ]);

        $this->runForTest($agent, UserMessage::of('loop'));

        $this->assertSame('single-step done', $this->lastAssistantContent($agent));
        $this->assertCount(1, $this->threadCompletions($agent));
        $this->assertCount(0, array_filter(
            $this->threadMessages($agent),
            static fn (Message $message): bool => $message instanceof ToolMessage,
        ));
    }

    public function testShutdownAbortsRemainingToolsInBatchWithStoppedResults(): void
    {
        $stub = new StubLlm();
        $laterRan = 0;
        /** @var Agent|null $agentRef */
        $agentRef = null;

        $completion3 = Completion::of(UserMessage::of(''), new AssistantMessage('', [
                new ToolCall('c1', 'boom', ['.caption' => 'Boom']),
                new ToolCall('c2', 'later', ['.caption' => 'Later']),
                new ToolCall('c3', 'later', ['.caption' => 'Later again']),
            ]));
        $completion3->completeId = 'chatcmpl-kill-batch';
        $completion3->finishReason = 'tool_calls';
        $completion3->usage = new TokenUsage();
        $stub->responses[] = $completion3;
        $stub->responses[] = StubLlm::assistant('should not continue');
        $this->container->set(LlmInterface::class, $stub);

        $toolsRegistry = $this->make(Tools::class, [
            'tools' => [
                'boom' => StubTool::config('boom', 'Kill self', static function () use (&$agentRef): string {
                    $agentRef->shutdown();

                    return 'boom-ok';
                }),
                'later' => StubTool::config('later', 'Must not run', static function () use (&$laterRan): string {
                    $laterRan++;

                    return 'later-ok';
                }),
            ],
        ]);
        $this->container->replace(ToolsInterface::class, $toolsRegistry);

        $agent = $this->make(Agent::class, [
                        'profile' => $this->testProfile(tools: ['boom', 'later']),
            ]);
        $agentRef = $agent;

        $error = $this->tryRun($agent, UserMessage::of('go'));
        $this->assertNotNull($error);
        $this->assertInstanceOf(RunCancelledException::class, $error);

        $this->assertSame(0, $laterRan);
        $inbox = new ReflectionProperty(Agent::class, 'inbox');
        $this->assertNull($inbox->getValue($agent));
        $this->assertCount(1, $stub->requests);

        $closure = $this->interruptCompletion($agent, 'cancelled');
        $this->assertNotNull($closure);
        $this->assertInstanceOf(AssistantAbortSignal::class, $closure->output);
        $this->assertSame(AssistantAbortSignal::KIND_CANCELLED, $closure->output->kind);
        $this->assertContains('go', array_map(
            static fn (Message $message): string => $message instanceof UserMessage ? (string) $message->text() : '',
            $this->threadMessages($agent),
        ));
        $this->assertNotEmpty(array_filter(
            $this->threadMessages($agent),
            static fn (Message $message): bool => $message instanceof ToolMessage,
        ));
    }

    public function testWorkerProcessesQueuedDeliveriesAsSeparateRuns(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('after block');
        $stub->responses[] = StubLlm::assistant('second run');
        $stub->responses[] = StubLlm::assistant('third run');
        $this->container->set(LlmInterface::class, $stub);

        $toolsRegistry = $this->make(Tools::class, [
            'tools' => [
                'block' => BlockingTool::class,
                ...$this->builtinToolConfig(),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);
        $this->container->replace(ToolsInterface::class, $toolsRegistry);

        $agent = $this->make(Agent::class, []);
        $this->bindProfileOverride($agent, $this->profileVariant($agent->profile(), [
            'tools' => [...$agent->profile()->tools, 'block'],
        ]));

        $lastUserContent = static function (array $request): string {
            $users = array_values(array_filter(
                $request,
                static fn (Message $message): bool => $message instanceof UserMessage,
            ));
            if ($users === []) {
                return '';
            }

            return $users[array_key_last($users)]->wire()[0]['text'];
        };

        $block = $this->startBlockedRun($agent, $stub, 'hold');
        $this->deliverForTest($agent, UserMessage::of('second'));
        $this->deliverForTest($agent, UserMessage::of('third'));
        $this->releaseBlockedRun($block);

        for ($i = 0; $i < 1000; $i++) {
            if (count($stub->requests) >= 4) {
                break;
            }
            \Swoole\Coroutine::yield();
        }

        $this->assertCount(4, $stub->requests);
        $this->assertSame('second', $lastUserContent($stub->requests[2]));
        $this->assertSame('third', $lastUserContent($stub->requests[3]));
    }

    public function testAsToolAsReplyLandsAnswerWithoutHostParaphrase(): void
    {
        $stub = new StubLlm();
        $stub->responses = [
            StubLlm::assistantWithToolCall('agent_file_research', [
                'message' => 'Summarize the topic.',
                'as_reply' => true,
            ]),
            StubLlm::assistant('Child final answer.'),
        ];
        $this->container->set(LlmInterface::class, $stub);

        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);
        $agent = $this->make(Agent::class, [
            'profile' => $this->profileWithTools($profiles->get('host'), 'agent_file_research'),
        ]);

        $this->runForTest($agent, UserMessage::of('go'));

        $this->assertSame('Child final answer.', $this->lastAssistantContent($agent));
        $this->assertCount(2, $stub->requests, 'host tool-call + child answer only; no host paraphrase step');

        $started = $this->container->get(\Psr\EventDispatcher\EventDispatcherInterface::class)
            ->getEventsByClass(\Switon\Tooling\Event\ToolStarted::class);
        $asToolStarted = false;
        foreach ($started as $event) {
            if ($event->call->name !== 'agent_file_research') {
                continue;
            }
            // Worker identity is minted inside the toolkit, so lifecycle events stay plain.
            $this->assertNotInstanceOf(\Switon\Ai\AsToolCall::class, $event->call);
            $asToolStarted = true;
        }
        $this->assertTrue($asToolStarted, 'host ToolStarted should carry the plain wire call');

        $foundPlaceholder = false;
        foreach ($this->threadCompletions($agent) as $row) {
            if (!$row->input instanceof ToolMessage) {
                continue;
            }
            foreach ($row->input->results as $result) {
                if ($result->name !== 'agent_file_research') {
                    continue;
                }
                $this->assertSame(Loop::AS_TOOL_AS_REPLY_PLACEHOLDER, Json::text($result->content));
                $this->assertInstanceOf(\Switon\Ai\AsToolResult::class, $result);
                $this->assertArrayNotHasKey('as_reply', $result->attributes);
                $this->assertArrayNotHasKey('.caption', $result->attributes);
                $this->assertNotSame('', $result->call->model);
                $this->assertSame('agent_file_research', $result->name);
                $foundPlaceholder = true;
            }
        }
        $this->assertTrue($foundPlaceholder);
    }

    public function testReplyContinuesTheToolLeftInTheBatch(): void
    {
        $stub = new StubLlm();
        $batch = Completion::of(UserMessage::of(''), new AssistantMessage('', [
            new ToolCall('call_ask', 'form_ask', [
                'id' => 'pick',
                'fields' => [[
                    'name' => 'choice',
                    'label' => 'Pick',
                    'type' => 'radio',
                    'options' => ['a', 'b'],
                ]],
            ]),
            new ToolCall('call_research', 'agent_file_research', [
                'message' => 'Summarize.',
                'as_reply' => true,
            ]),
        ]));
        $batch->completeId = 'stub-open-as-reply';
        $batch->finishReason = 'tool_calls';
        $stub->responses = [
            $batch,
            StubLlm::assistant('host after reply'),
        ];
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(\Switon\Agency\Tool\FormAskTool::class, [
            'class' => \Switon\Agency\Tool\FormAskTool::class,
            'types' => ['radio'],
        ]);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => array_merge($this->builtinToolConfig(), [
                'form_ask' => \Switon\Agency\Tool\FormAskTool::class,
            ]),
            'toolkits' => $this->builtinToolkitConfig(),
        ]));

        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);
        $agent = $this->make(Agent::class, [
            'profile' => $this->profileWithTools($profiles->get('host'), 'form_ask', 'agent_file_research'),
        ]);

        $this->runForTest($agent, UserMessage::of('go'));
        $this->assertInstanceOf(\Switon\Ai\Suspension\ToolReplyInterrupt::class, $agent->thread()->lastTurn()?->interrupt);

        $this->runForTest($agent, \Switon\Ai\Suspension\ToolReplyContinuation::of('call_ask', [
            'outcome' => 'answered',
            'id' => 'pick',
            'values' => ['choice' => 'a'],
        ]));

        $this->assertSame('host after reply', $this->lastAssistantContent($agent));
        $this->assertCount(2, $stub->requests);
        $toolRows = 0;
        $sawResearch = false;
        foreach ($this->threadCompletions($agent) as $row) {
            if (!$row->input instanceof ToolMessage) {
                continue;
            }
            $toolRows++;
            foreach ($row->input->results as $result) {
                if ($result->name !== 'agent_file_research') {
                    continue;
                }
                $this->assertSame(Loop::AS_TOOL_AS_REPLY_PLACEHOLDER, Json::text($result->content));
                $sawResearch = true;
            }
        }
        $this->assertSame(1, $toolRows);
        $this->assertTrue($sawResearch);
    }

    public function testAsToolAsReplyIgnoredWhenNotLastToolInBatch(): void
    {
        $stub = new StubLlm();
        $c_stub = Completion::of(UserMessage::of(''), new AssistantMessage('', [
                    new ToolCall('call_1', 'agent_file_research', [
                        'message' => 'First specialist.',
                        'as_reply' => true,
                    ]),
                    new ToolCall('call_2', 'agent_file_research', [
                        'message' => 'Second specialist.',
                    ]),
                ]));
        $c_stub->completeId = 'stub';
        $c_stub->finishReason = 'tool_calls';
        $stub->responses = [
            $c_stub,
            StubLlm::assistant('first-child'),
            StubLlm::assistant('second-child'),
            StubLlm::assistant('host rewrite'),
        ];
        $this->container->set(LlmInterface::class, $stub);

        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);
        $agent = $this->make(Agent::class, [
            'profile' => $this->profileWithTools($profiles->get('host'), 'agent_file_research'),
        ]);

        $this->runForTest($agent, UserMessage::of('go'));

        $this->assertSame('host rewrite', $this->lastAssistantContent($agent));
        $this->assertGreaterThanOrEqual(3, count($stub->requests), 'host continues after tools when as_reply is not on the last call');

        foreach ($this->threadCompletions($agent) as $row) {
            if (!$row->input instanceof ToolMessage) {
                continue;
            }
            foreach ($row->input->results as $result) {
                if ($result->name !== 'agent_file_research') {
                    continue;
                }
                $this->assertNotSame(Loop::AS_TOOL_AS_REPLY_PLACEHOLDER, Json::text($result->content));
            }
        }
    }

    public function testReplyContinuesCallsLeftInTheBatch(): void
    {
        $stub = new StubLlm();
        $batch = Completion::of(UserMessage::of(''), new AssistantMessage('', [
            new ToolCall('call_echo', 'echo', ['.caption' => 'Echo']),
            new ToolCall('call_ask', 'form_ask', [
                'id' => 'pick',
                'fields' => [[
                    'name' => 'choice',
                    'label' => 'Pick',
                    'type' => 'radio',
                    'options' => ['a', 'b'],
                ]],
                '.caption' => 'Ask',
            ]),
            new ToolCall('call_later', 'echo', ['.caption' => 'Later']),
        ]));
        $batch->completeId = 'stub-batch';
        $batch->finishReason = 'tool_calls';
        $stub->responses[] = $batch;
        $stub->responses[] = StubLlm::assistant('after reply');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(\Switon\Agency\Tool\FormAskTool::class, [
            'class' => \Switon\Agency\Tool\FormAskTool::class,
            'types' => ['radio', 'input', 'select', 'textarea', 'choice'],
        ]);
        $laterHits = 0;
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo', static function (array $arguments) use (&$laterHits): string {
                    if (($arguments['.caption'] ?? '') === 'Later') {
                        $laterHits++;

                        return 'later-ok';
                    }

                    return 'echo-ok';
                }),
                'form_ask' => \Switon\Agency\Tool\FormAskTool::class,
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]));

        $agent = $this->make(Agent::class, [
            'profile' => $this->profileWithTools($this->testProfile(), 'echo', 'form_ask'),
        ]);
        $this->runForTest($agent, UserMessage::of('go'));

        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Suspended, $turn->status);
        $this->assertInstanceOf(\Switon\Ai\Suspension\ToolReplyInterrupt::class, $turn->interrupt);
        $this->assertSame('call_ask', $turn->interrupt->callId);

        $landed = [];
        foreach ($this->threadCompletions($agent) as $row) {
            if (!$row->input instanceof ToolMessage) {
                continue;
            }
            foreach ($row->input->results as $result) {
                $landed[$result->id] = $result;
            }
        }
        $this->assertSame('echo-ok', $landed['call_echo']->content ?? null);
        $this->assertArrayNotHasKey('call_ask', $landed);
        $this->assertArrayNotHasKey('call_later', $landed);
        $this->assertSame(0, $laterHits);

        $this->runForTest($agent, \Switon\Ai\Suspension\ToolReplyContinuation::of('call_ask', [
            'outcome' => 'answered',
            'id' => 'pick',
            'values' => ['choice' => 'a'],
        ]));
        $this->assertSame(1, $laterHits);
        $this->assertSame('after reply', $this->lastAssistantContent($agent));
        $landed = [];
        $toolRows = 0;
        foreach ($this->threadCompletions($agent) as $row) {
            if (!$row->input instanceof ToolMessage) {
                continue;
            }
            $toolRows++;
            foreach ($row->input->results as $result) {
                $landed[$result->id] = $result;
            }
        }
        $this->assertSame(1, $toolRows);
        $this->assertSame('echo-ok', $landed['call_echo']->content ?? null);
        $this->assertArrayHasKey('call_ask', $landed);
        $this->assertSame('later-ok', $landed['call_later']->content ?? null);
    }

    /** @param list<Message> $request */
    protected function requestIncludesWireContaining(array $request, string $needle): bool
    {
        foreach ($request as $message) {
            if (!$message instanceof Message) {
                continue;
            }

            $wire = trim((string) ($message->wire()[0]['text'] ?? ''));
            if ($wire !== '' && str_contains($wire, $needle)) {
                return true;
            }
        }

        return false;
    }
}
