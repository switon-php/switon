<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use Switon\Ai\ToolResult;
use ArrayIterator;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Agency\Agent;
use Switon\Ai\Event\DeliverySettled;
use Switon\Ai\Event\DeliveryStarted;
use Switon\Agency\Event\StepRequesting;
use Switon\Ai\Event\StepResponded;
use Switon\Tooling\Event\ToolFinished;
use Switon\Tooling\Event\ToolStarted;
use Switon\Agency\Exception;
use Switon\Agency\Tests\Fixtures\StatefulRootAgent;
use Switon\Agency\Tests\Fixtures\ThrowOnShutdownAgent;
use Switon\Agency\ProfilesInterface;
use Switon\Ai\TurnStatus;
use Switon\Thread\Threads;
use Switon\Thread\ThreadsInterface;
use Switon\Agency\Tests\Fixtures\StubTool;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Ai\Signal\AssistantSignal;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;
use Switon\Core\FilesystemInterface;
use Switon\Core\Json;
use Switon\Core\Strings;
use Swoole\Coroutine;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Part\TextPart;
use Switon\Ai\Message\SystemMessage;
use Switon\Ai\Thread;
use Switon\Ai\Message;
use Switon\Ai\TokenUsage;
use Switon\Ai\ToolCall;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Turn;
use Switon\Ai\Suspension\RetryContinuation;
use Switon\Ai\Message\UserMessage;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Ai\DiscardTurnLetter;
use Switon\Ai\Signal;
use Switon\Ai\Signal\AssistantAbortSignal;
use Switon\Agency\Thread\TaskFinishSignal;
use ReflectionProperty;
use Switon\Agency\Exception\RunCancelledException;
use Switon\Eventing\EventDispatcherInterface as EventingEventDispatcherInterface;
use RuntimeException;

#[CoversClass(Agent::class)]
#[Group('coroutine')]
class AgentTest extends TestCase
{
    public function testRunPrependsConfiguredPrompt(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('Done');

        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(prompt: 'You are helpful.'),
        ]);
        $this->runForTest($agent, UserMessage::of('Hi'));

        $this->assertSame('You are helpful.', $agent->profile()->prompt);
        $this->assertSame(SystemMessage::Role, $stub->requests[0][0]->role);
        $this->assertSame('You are helpful.', $stub->requests[0][0]->content);
        $this->assertCount(2, $stub->requests[0]);
    }

    public function testRunStopsOnAssistantMessageWithoutTools(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('Done');

        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, [
                    ]);
        $turn = $this->runForTest($agent, UserMessage::of('Hi'));
        $this->assertNotNull($turn->lastCompletion());
        $this->assertSame('Done', $turn->lastCompletion()->output->content);

        $messages = $this->persistedMessages($agent->threadId());
        $this->assertCount(2, $messages);
        $this->assertSame('user', $messages[0]->role);
        $this->assertSame('assistant', $messages[1]->role);
        $this->assertSame('Done', $messages[1]->content);
    }

    public function testExecuteRunMintsANewTurnIdForTaskFinish(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('first');
        $stub->responses[] = StubLlm::assistant('continued');
        $stub->responses[] = StubLlm::assistant('second turn');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, [
                    ]);
        $this->runDeliveryForTest($agent, UserMessage::of('hello'));
        $firstRow = $this->threadCompletions($agent)[0];
        $firstTurnId = $this->turnIdFor($agent, $firstRow);
        $this->assertNotSame('', $firstTurnId);
        $this->assertSame(1, $firstRow->step);
        $this->assertNotSame('', $firstRow->completeId);
        foreach ($this->threadCompletions($agent) as $row) {
            $this->assertSame($firstTurnId, $this->turnIdFor($agent, $row));
        }

        $this->runDeliveryForTest(
            $agent,
            new TaskFinishSignal(['task_id' => 't1', 'thread_id' => 'host.t10001', 'status' => 'completed'], 'Continue.'),
        );
        $continuationRow = null;
        foreach ($this->threadCompletions($agent) as $row) {
            if ($row->input instanceof TaskFinishSignal
                && $row->step === 1
                && $row->output !== null) {
                $continuationRow = $row;
                break;
            }
        }
        $this->assertNotNull($continuationRow);
        $finishTurnId = $this->turnIdFor($agent, $continuationRow);
        $this->assertNotSame('', $finishTurnId);
        $this->assertNotSame($firstTurnId, $finishTurnId);
        $this->assertNotSame('', $continuationRow->completeId);
        $this->assertNotSame($firstRow->completeId, $continuationRow->completeId);

        $afterFinish = array_slice($this->threadCompletions($agent), 1);
        $this->assertNotEmpty($afterFinish);
        foreach ($afterFinish as $row) {
            $this->assertSame($finishTurnId, $this->turnIdFor($agent, $row));
        }

        $this->runDeliveryForTest($agent, UserMessage::of('next question'));
        $nextTurnId = null;
        foreach ($this->threadCompletions($agent) as $row) {
            if ($row->input instanceof UserMessage && $row->input->wire()[0]['text'] === 'next question') {
                $nextTurnId = $this->turnIdFor($agent, $row);
                $this->assertSame(1, $row->step);
                $this->assertNotSame('', $row->completeId);
                break;
            }
        }
        $this->assertNotNull($nextTurnId);
        $this->assertNotSame($firstTurnId, $nextTurnId);
        $this->assertNotSame($finishTurnId, $nextTurnId);
    }

    public function testRunScopeTurnAnchors(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('ok');
        $stub->responses[] = StubLlm::assistant('continued');
        $stub->responses[] = $this->stubTaskFinish('done');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, [
                    ]);

        $this->runForTest($agent, UserMessage::of('hello'));
        $first = $this->threadCompletions($agent)[0];
        $this->assertNotSame('', $this->turnIdFor($agent, $first));

        $this->runForTest(
            $agent,
            new TaskFinishSignal(['task_id' => 't1', 'thread_id' => 'host.t10001', 'status' => 'completed'], 'Continue.'),
        );
        $last = $this->threadCompletions($agent)[count($this->threadCompletions($agent)) - 1];
        $this->assertNotSame($this->turnIdFor($agent, $first), $this->turnIdFor($agent, $last));
        $this->assertNotSame($first->completeId, $last->completeId);

        $lite = $this->spawnDelegated($agent, 'lite');
        $this->openTaskForTest($lite, 'task-anchor');
        $this->deliverSubForTest($lite, UserMessage::of('work'));
        $assign = $this->threadCompletions($lite)[0];
        $this->assertNotSame('', $this->turnIdFor($lite, $assign));
        $this->assertInstanceOf(\Switon\Agency\Thread\TaskAssignMessage::class, $assign->input);
        $this->assertSame('task-anchor', $assign->input->taskId);
    }

    public function testDiscardTurnLetterAppliesViaInboxWithoutModelStep(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('first');
        $stub->responses[] = StubLlm::assistant('second');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, []);
        $this->runForTest($agent, UserMessage::of('keep'));
        $this->runForTest($agent, UserMessage::of('drop'));
        $tipId = $agent->thread()->lastTurn()?->turnId;
        $this->assertNotNull($tipId);
        $this->assertCount(2, $agent->thread()->turns);
        $beforeCalls = $stub->completeCalls;

        $this->runForTest($agent, DiscardTurnLetter::of($tipId));

        $this->assertCount(1, $agent->thread()->turns);
        $this->assertSame('keep', $this->threadCompletions($agent)[0]->input->wire()[0]['text'] ?? null);
        $this->assertSame($beforeCalls, $stub->completeCalls, 'discard must not call the model');
    }

    public function testTaskAssignKeepsIndependentTaskId(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = $this->stubTaskFinish('done');
        $this->container->set(LlmInterface::class, $stub);

        $main = $this->make(Agent::class, [
                    ]);
        $lite = $this->spawnDelegated($main, 'lite');
        $this->openTaskForTest($lite, 'delegation-anchor');
        $this->deliverSubForTest($lite, UserMessage::of('work'));

        $row = $this->threadCompletions($lite)[0];
        $this->assertNotSame('', $this->turnIdFor($lite, $row));
        $this->assertNotSame('', $row->completeId);
        $this->assertInstanceOf(\Switon\Agency\Thread\TaskAssignMessage::class, $row->input);
        $this->assertSame('delegation-anchor', $row->input->taskId);
        $this->assertSame('work', $row->input->wire()[0]['text']);
    }

    public function testSameTurnStepTwoAssignsDistinctCompleteId(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo');
        $stub->responses[] = StubLlm::assistant('done');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->makeToolsWithEchoAndSecret());

        $agent = $this->make(Agent::class, [
                        'profile' => $this->testProfile(tools: ['echo']),
            ]);
        $this->runForTest($agent, UserMessage::of('ping'));

        $llmSteps = array_values(array_filter(
            $this->threadCompletions($agent),
            static fn (Completion $row): bool => $row->output !== null && $row->step > 0,
        ));
        $this->assertCount(2, $llmSteps);

        $step1 = $llmSteps[0];
        $step2 = $llmSteps[1];
        $this->assertSame(1, $step1->step);
        $this->assertSame(2, $step2->step);
        $this->assertSame($this->turnIdFor($agent, $step1), $this->turnIdFor($agent, $step2));
        $this->assertNotSame('', $step1->completeId);
        $this->assertNotSame('', $step2->completeId);
        $this->assertNotSame($step1->completeId, $step2->completeId);
    }

    public function testRunPersistsExportedState(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('Done');

        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(StatefulRootAgent::class, [
                    ]);
        $agent->setCursor(7);
        $this->persistAgentState($agent);
        $this->runForTest($agent, UserMessage::of('Hi'));

        $states = $this->container->get(ThreadsInterface::class);
        $loaded = $this->loadThread($agent->thread()->datadir, $agent->thread()->id)->state;
        $this->assertSame($this->agentState($agent), $loaded);
        $this->assertSame(7, $loaded['cursor']);
    }

    public function testSetSegmentsPersistsState(): void
    {
        $agent = $this->make(Agent::class, [
                    ]);
        $states = $this->container->get(ThreadsInterface::class);

        $state = $this->agentState($agent);
        $state['segments'] = ['extra.one', 'extra.two'];
        $agent->thread()->state = $state;
        $this->persistAgentState($agent);

        $this->assertSame(['extra.one', 'extra.two'], $this->loadThread($agent->thread()->datadir, $agent->thread()->id)->state['segments'] ?? []);
    }

    public function testRunExecutesToolCallsAndContinues(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall(
            'get_weather',
            ['city' => 'NYC'],
            'call_1',
            ['caption' => 'Checking NYC weather.'],
        );
        $stub->responses[] = StubLlm::assistant('It is 72F in NYC.');

        $toolsRegistry = $this->make(Tools::class, [
            'tools' => [
                'get_weather' => StubTool::config(
                    'get_weather',
                    'Weather lookup',
                    static fn (): string => '{"temp":72}',
                ),
            ],
        ]);

        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $toolsRegistry);

        $agent = $this->make(Agent::class, [
                        'profile' => $this->testProfile(tools: ['get_weather']),
            ]);
        $this->runForTest($agent, UserMessage::of('Weather in NYC?'));

        $messages = $this->persistedMessages($agent->threadId());
        $this->assertCount(4, $messages);
        $this->assertSame('tool', $messages[2]->role);
        $this->assertSame('{"temp":72}', $messages[2]->results[0]->content);
        $this->assertSame('assistant', $messages[3]->role);
        $this->assertSame('It is 72F in NYC.', $messages[3]->content);
        $this->assertSame(
            'get_weather',
            $this->eventDispatcher()->getEventsByClass(ToolStarted::class)[0]->call->name,
        );
    }

    public function testRunRespectsToolAllowList(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo');
        $stub->responses[] = StubLlm::assistant('ok');

        $toolsRegistry = $this->makeToolsWithEchoAndSecret();

        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $toolsRegistry);

        $agent = $this->make(Agent::class, [
                        'profile' => $this->testProfile(tools: ['echo']),
            ]);
        $this->runForTest($agent, UserMessage::of('ping'));

        $started = $this->eventDispatcher()->getEventsByClass(DeliveryStarted::class)[0];
        $charter = $started->sender->charter();
        $this->assertArrayHasKey('echo', $charter->tools);
        $this->assertEqualsCanonicalizing(['echo'], $this->agentProp($agent, 'profileTools'));
    }

    public function testRunRejectsToolOutsideAllowList(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('secret');
        $stub->responses[] = StubLlm::assistantWithToolCall('secret');
        $stub->responses[] = StubLlm::assistant('stopped after deny');

        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->makeToolsWithEchoAndSecret());

        $agent = $this->make(Agent::class, [
                        'profile' => $this->testProfile(tools: ['echo']),
            ]);
        $this->runForTest($agent, UserMessage::of('ping'));

        $tool = null;
        foreach ($this->persistedAgentMessages($agent) as $message) {
            if ($message instanceof ToolMessage && $message->results[0]->name === 'secret') {
                $tool = $message;
                break;
            }
        }
        $this->assertNotNull($tool);
        $this->assertStringContainsString('not allowed', Json::text($tool->results[0]->wire()));
        $this->assertNull($this->interruptCompletion($agent));
    }

    public function testRunWithDefaultToolsRejectsToolCall(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo');
        $stub->responses[] = StubLlm::assistantWithToolCall('echo');
        $stub->responses[] = StubLlm::assistant('stopped after deny');

        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->makeToolsWithEcho());

        $agent = $this->make(Agent::class, [
                    ]);
        $this->runForTest($agent, UserMessage::of('ping'));

        $tool = null;
        foreach ($this->persistedAgentMessages($agent) as $message) {
            if ($message instanceof ToolMessage && $message->results[0]->name === 'echo') {
                $tool = $message;
                break;
            }
        }
        $this->assertNotNull($tool);
        $this->assertStringContainsString('not allowed', Json::text($tool->results[0]->wire()));
        $this->assertNull($this->interruptCompletion($agent));
    }

    public function testRunBindsAllowedToolWhenBatchIncludesUnknown(): void
    {
        $stub = new StubLlm();
        $echoRan = 0;
        $completion4 = Completion::of(UserMessage::of(''), new AssistantMessage('', [
                new ToolCall('c1', 'echo', ['text' => 'hi']),
                new ToolCall('c2', 'nope', ['text' => 'x']),
            ]));
        $completion4->completeId = 'chatcmpl-mix';
        $completion4->finishReason = 'tool_calls';
        $completion4->usage = new TokenUsage();
        $stub->responses[] = $completion4;
        $stub->responses[] = StubLlm::assistant('done');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo', static function () use (&$echoRan): string {
                    $echoRan++;

                    return 'pong';
                }),
                ...$this->builtinToolConfig(),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]));

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['echo']),
        ]);
        $this->runForTest($agent, UserMessage::of('ping'));

        $this->assertSame(1, $echoRan);
        $echo = null;
        $nope = null;
        foreach ($this->persistedAgentMessages($agent) as $message) {
            if (!$message instanceof ToolMessage) {
                continue;
            }
            foreach ($message->results as $result) {
                if ($result->name === 'echo') {
                    $echo = $result;
                }
                if ($result->name === 'nope') {
                    $nope = $result;
                }
            }
        }
        $this->assertNotNull($echo);
        $this->assertNotNull($nope);
        $this->assertStringContainsString('not allowed', Json::text($nope->wire()));
    }

    public function testRunDispatchesLifecycleEvents(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo');
        $stub->responses[] = StubLlm::assistant('ok');

        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->makeToolsWithEcho());

        $agent = $this->make(Agent::class, [
                        'profile' => $this->testProfile(tools: ['echo']),
            ]);
        $this->runForTest($agent, UserMessage::of('ping'));

        $dispatcher = $this->eventDispatcher();
        $this->assertCount(1, $dispatcher->getEventsByClass(DeliveryStarted::class));
        $this->assertCount(2, $dispatcher->getEventsByClass(StepRequesting::class));
        $this->assertCount(2, $dispatcher->getEventsByClass(StepResponded::class));
        $responded = $dispatcher->getEventsByClass(StepResponded::class);
        $this->assertSame(1, $responded[0]->completion->step);
        $this->assertSame(2, $responded[1]->completion->step);
        $this->assertCount(1, $dispatcher->getEventsByClass(ToolStarted::class));
        $this->assertCount(1, $dispatcher->getEventsByClass(ToolFinished::class));
        $this->assertSame('echo', $dispatcher->getEventsByClass(ToolFinished::class)[0]->call->name);
        $this->assertSame(
            'Running tool.',
            $dispatcher->getEventsByClass(ToolStarted::class)[0]->call->arguments['.caption'],
        );
    }

    public function testRunDoesNotAppendStripGuideImplicitly(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('Done');

        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => $this->builtinToolConfig(),
            'toolkits' => $this->builtinToolkitConfig(),
        ]);

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(prompt: 'You are helpful.', tools: ['echo']),
        ]);
        $this->runForTest($agent, UserMessage::of('Hi'));

        $system = $stub->requests[0][0]->content;
        $this->assertStringNotContainsString('Compress context to summaries', $system);
        $this->assertStringContainsString('You are helpful.', $system);
    }

    public function testRunInjectsCaptionSchemaIntoTools(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('Done');

        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->makeToolsWithEcho());

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(prompt: 'You are helpful.', tools: ['echo']),
        ]);
        $this->runForTest($agent, UserMessage::of('Hi'));

        $system = $stub->requests[0][0]->content;
        $this->assertStringNotContainsString('Tool UI captions', $system);
        $this->assertStringContainsString('You are helpful.', $system);
    }

    public function testRunAcceptsToolCallWithoutUi(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo', ['n' => 1], 'call_1', null);
        $stub->responses[] = StubLlm::assistant('ok');

        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->makeToolsWithEcho());

        $agent = $this->make(Agent::class, [
                        'profile' => $this->testProfile(tools: ['echo']),
            ]);
        $this->runForTest($agent, UserMessage::of('ping'));

        $messages = $this->persistedMessages($agent->threadId());
        $this->assertSame('ok', $messages[array_key_last($messages)]->content);
        $this->assertSame(
            '',
            $this->eventDispatcher()->getEventsByClass(ToolStarted::class)[0]->call->arguments['.caption'] ?? '',
        );
    }

    public function testRunRecordsToolErrorInConversation(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('explode', ['value' => 'x'], 'call_1', null);
        $stub->responses[] = StubLlm::assistant('Done.');
        $this->container->set(LlmInterface::class, $stub);

        $toolsRegistry = $this->make(Tools::class, [
            'tools' => [
                'explode' => StubTool::config(
                    'explode',
                    'Explode tool',
                    static function (): never {
                        throw new RuntimeException('Boom');
                    },
                ),
            ],
        ]);
        $this->container->replace(ToolsInterface::class, $toolsRegistry);

        $agent = $this->make(Agent::class, [
                        'profile' => $this->testProfile(tools: ['explode']),
            ]);

        $this->assertNull($this->tryRun($agent, UserMessage::of('Hi')));

        $tool = null;
        foreach ($this->persistedMessages($agent->threadId()) as $message) {
            if ($message instanceof ToolMessage && $message->results[0]->name === 'explode') {
                $tool = $message;
                break;
            }
        }
        $this->assertNotNull($tool);
        $this->assertStringContainsString('Boom', Json::text($tool->results[0]->wire()));

        $persisted = Json::stringify($this->persistedMessages($agent->threadId()));
        $this->assertStringContainsString('Boom', $persisted);
        $this->assertStringContainsString('Hi', $persisted);
    }

    public function testRunUsesProfileDefaultMaxStepsOnBareProfile(): void
    {
        $stub = new StubLlm();
        $stub->responses = [
            ...array_map(static fn (): Completion => StubLlm::assistantWithToolCall('loop'), range(1, 9)),
            StubLlm::assistant('Done looping.'),
        ];

        $toolsRegistry = $this->make(Tools::class, [
            'tools' => [
                'loop' => StubTool::config('loop', 'Loop', static fn (): string => 'again'),
            ],
        ]);

        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $toolsRegistry);

        $agent = $this->make(Agent::class, [
                        'profile' => $this->testProfile(tools: ['loop']),
            ]);

        $this->runForTest($agent, UserMessage::of('loop'));

        $this->assertSame('Done looping.', $this->lastAssistantContent($agent));
        $this->assertNull($this->interruptCompletion($agent, 'budget_exceeded'));
        $this->assertCount(10, $stub->requests);
    }

    public function testRunClosesWithBudgetExceededWhenMaxStepsReached(): void
    {
        $stub = new StubLlm();
        $stub->responses = [
            StubLlm::assistantWithToolCall('loop', callId: 'call_budget_1'),
            static function (Thread $thread, Message $input, Charter $charter): Completion {
                Assert::assertArrayHasKey('loop', $charter->tools);
                Assert::assertNull($charter->toolChoice);
                Assert::assertInstanceOf(ToolMessage::class, $input);
                Assert::assertSame('again', $input->results[0]->content);
                Assert::assertInstanceOf(TextPart::class, $input->results[0]->parts[0]);
                Assert::assertStringContainsString('last chance', $input->results[0]->parts[0]->text);

                return StubLlm::assistantWithToolCall('loop', callId: 'call_budget_2');
            },
        ];

        $toolsRegistry = $this->make(Tools::class, [
            'tools' => [
                'loop' => StubTool::config('loop', 'Loop', static fn (): string => 'again'),
            ],
        ]);

        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $toolsRegistry);

        $agent = $this->make(Agent::class, [
                        'profile' => $this->testProfile(tools: ['loop'], maxSteps: 2),
            ]);

        $this->runForTest($agent, UserMessage::of('loop'));

        $budget = $this->interruptCompletion($agent, 'budget_exceeded');
        $this->assertNotNull($budget);
        $this->assertInstanceOf(\Switon\Ai\Signal\AssistantAbortSignal::class, $budget->output);
        $this->assertStringContainsString('step budget is exhausted', $budget->output->body);
        $this->assertInstanceOf(ToolMessage::class, $budget->input);
        $this->assertSame([], $budget->input->results[0]->parts);
        $this->assertCount(2, $stub->requests);
    }

    public function testLastModelStepCanFinishWithText(): void
    {
        $stub = new StubLlm();
        $stub->responses = [
            StubLlm::assistantWithToolCall('loop', callId: 'call_warn_1'),
            static function (Thread $thread, Message $input): Completion {
                Assert::assertInstanceOf(ToolMessage::class, $input);
                Assert::assertSame('again', $input->results[0]->content);
                Assert::assertInstanceOf(TextPart::class, $input->results[0]->parts[0]);
                Assert::assertStringContainsString('last chance', $input->results[0]->parts[0]->text);

                return StubLlm::assistant('Here is progress so far.');
            },
        ];

        $toolsRegistry = $this->make(Tools::class, [
            'tools' => [
                'loop' => StubTool::config('loop', 'Loop', static fn (): string => 'again'),
            ],
        ]);

        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $toolsRegistry);

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['loop'], maxSteps: 2),
        ]);

        $this->runForTest($agent, UserMessage::of('loop'));

        $this->assertSame('Here is progress so far.', $this->lastAssistantContent($agent));
        $this->assertNull($this->interruptCompletion($agent, 'budget_exceeded'));
        $this->assertCount(2, $stub->requests);
    }

    public function testRunClosesWithBudgetExceededOnSingleStepToolCall(): void
    {
        $stub = new StubLlm();
        $stub->responses = [
            static function (Thread $thread, Message $input, Charter $charter): Completion {
                Assert::assertArrayHasKey('loop', $charter->tools);

                return StubLlm::assistantWithToolCall('loop');
            },
        ];

        $toolsRegistry = $this->make(Tools::class, [
            'tools' => [
                'loop' => StubTool::config('loop', 'Loop', static fn (): string => 'again'),
            ],
        ]);

        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $toolsRegistry);

        $agent = $this->make(Agent::class, [
                        'profile' => $this->testProfile(tools: ['loop'], maxSteps: 1),
            ]);

        $this->runForTest($agent, UserMessage::of('loop'));

        $budget = $this->interruptCompletion($agent, 'budget_exceeded');
        $this->assertNotNull($budget);
        $this->assertInstanceOf(\Switon\Ai\Signal\AssistantAbortSignal::class, $budget->output);
        $this->assertInstanceOf(ToolMessage::class, $budget->input);
        $this->assertSame([], $budget->input->results[0]->parts);
        $this->assertCount(1, $stub->requests);
        $this->assertCount(2, $this->threadCompletions($agent));
    }

    public function testContinuationDoesNotResetTheTurnStepBudget(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('loop', callId: 'call_budget_1');
        $stub->responses[] = static function (): never {
            throw new RuntimeException('llm down');
        };
        $stub->responses[] = StubLlm::assistantWithToolCall('loop', callId: 'call_budget_2');

        $toolsRegistry = $this->make(Tools::class, [
            'tools' => [
                'loop' => StubTool::config('loop', 'Loop', static fn (): string => 'again'),
            ],
        ]);
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $toolsRegistry);

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['loop'], maxSteps: 2),
        ]);

        $error = $this->tryRun($agent, UserMessage::of('loop'));
        $this->assertInstanceOf(RuntimeException::class, $error);

        $this->runForTest($agent, RetryContinuation::of());

        $budget = $this->interruptCompletion($agent, 'budget_exceeded');
        $this->assertNotNull($budget);
        $this->assertCount(3, $stub->requests);
    }

    public function testRunLandsToolResultsWhenNextLlmThrows(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo');
        $stub->responses[] = static function (): never {
            throw new RuntimeException('stub LLM down');
        };

        $toolsRegistry = $this->make(Tools::class, [
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'echo-ok'),
            ],
        ]);

        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $toolsRegistry);

        $agent = $this->make(Agent::class, [
                        'profile' => $this->testProfile(tools: ['echo']),
            ]);

        $error = $this->tryRun($agent, UserMessage::of('go'));
        $this->assertNotNull($error);
        $this->assertStringContainsString('stub LLM down', $error->getMessage());

        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Suspended, $turn->status);
        $this->assertNull($this->interruptCompletion($agent, 'exception'));
    }

    public function testRunLandsExceptionSignalWhenFirstLlmThrows(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = static function (): never {
            throw new RuntimeException('stub LLM down');
        };
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(),
        ]);

        $error = $this->tryRun($agent, UserMessage::of('hello'));
        $this->assertNotNull($error);
        $this->assertStringContainsString('stub LLM down', $error->getMessage());

        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Suspended, $turn->status);
        $this->assertSame('hello', $turn->completions[0]->input->wire()[0]['text'] ?? null);
        $this->assertNull($this->interruptCompletion($agent, 'exception'));
    }

    public function testRunPersistsToolTimingOnToolResult(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo');
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

        foreach ($this->threadCompletions($agent) as $completion) {
            if ($completion->input instanceof ToolMessage) {
                $this->assertNotNull($completion->input->results[0]->timing);

                return;
            }
        }

        $this->fail('Expected persisted tool result.');
    }

    public function testRunRebindsDirectToolResultToActualCall(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo', [], 'actual-call');
        $stub->responses[] = StubLlm::assistant('done');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'echo' => StubTool::config(
                    'echo',
                    'Echo',
                    static fn (): ToolResult => new ToolResult(
                        'echo-ok',
                        'wrong-call',
                        'wrong-tool',
                    ),
                ),
            ],
        ]));

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['echo']),
        ]);
        $this->runForTest($agent, UserMessage::of('go'));

        foreach ($this->threadCompletions($agent) as $completion) {
            if (!$completion->input instanceof ToolMessage) {
                continue;
            }
            $result = $completion->input->results[0];
            $this->assertSame('echo-ok', $result->content);
            $this->assertSame('actual-call', $result->id);
            $this->assertSame('echo', $result->name);

            return;
        }

        $this->fail('Expected persisted tool result.');
    }

    public function testRunTreatsDomainStatusAsToolData(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo');
        $stub->responses[] = StubLlm::assistant('done');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo', static fn (): array => ['status' => 'done']),
            ],
        ]));

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['echo']),
        ]);
        $this->runForTest($agent, UserMessage::of('go'));

        foreach ($this->threadCompletions($agent) as $completion) {
            if ($completion->input instanceof ToolMessage) {
                $this->assertSame(['status' => 'done'], $completion->input->results[0]->content);

                return;
            }
        }

        $this->fail('Expected persisted tool result.');
    }

    public function testToolFinishedListenerExceptionPropagates(): void
    {
        $dispatcher = $this->createStub(EventingEventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            static function (object $event): object {
                if ($event instanceof ToolFinished) {
                    throw new RuntimeException('listener failed');
                }

                return $event;
            },
        );
        $this->container->replace(EventingEventDispatcherInterface::class, $dispatcher);

        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo');
        $stub->responses[] = StubLlm::assistant('done');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'executed'),
            ],
        ]));

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['echo']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('listener failed');
        $this->runForTest($agent, UserMessage::of('go'));
    }

    public function testRunWithMissingAssistantOutputEndsTurnWithoutSuspend(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = Completion::of(UserMessage::of(''));
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class);
        $c_prior_step = Completion::of(UserMessage::of('first'), new AssistantMessage('ok'));
        $c_prior_step->completeId = 'prior-step';
        $c_prior_step->step = 1;
        $this->threadAppend($agent->thread(), $c_prior_step);
        $agent->thread()->lastTurn()->status = TurnStatus::Completed;

        $turn = $this->runForTest($agent, UserMessage::of('second'));

        $this->assertSame(TurnStatus::Completed, $turn->status);
        $this->assertNull($this->interruptCompletion($agent));
    }

    public function testRunWithBlankAssistantOutputEndsTurnWithoutSuspend(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class);
        $turn = $this->runForTest($agent, UserMessage::of('hello'));

        $this->assertSame(TurnStatus::Completed, $turn->status);
        $this->assertNull($this->interruptCompletion($agent));
        $this->assertSame('', $turn->lastCompletion()?->output?->content);
        $this->assertSame('stop', $turn->lastCompletion()?->finishReason);
    }

    public function testConfigToolsExpandOnMaterialize(): void
    {
        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => new ArrayIterator([
                'echo' => StubTool::config('echo', 'Echo'),
                'grep' => StubTool::config('grep', 'Grep'),
            ]),
            'toolkits' => $this->builtinToolkitConfig(),
        ]);

        $agent = $this->make(Agent::class, [
                        'profile' => $this->testProfile(tools: ['echo', 'grep']),
            ]);

        $this->assertEqualsCanonicalizing(['echo', 'grep'], $this->agentProp($agent, 'profileTools'));
    }

    public function testWildcardToolsExpandOnCreateSub(): void
    {
        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => new ArrayIterator([
                'echo' => StubTool::config('echo', 'Echo'),
                'grep' => StubTool::config('grep', 'Grep'),
            ]),
            'toolkits' => $this->builtinToolkitConfig(),
        ]);

        $host = $this->make(Agent::class, [
                    ]);
        $sub = $this->spawnDelegated($host, 'all');

        $this->assertEqualsCanonicalizing(
            ['echo', 'grep'],
            $this->agentProp($sub, 'profileTools'),
        );
    }

    public function testConfigToolsAppliedOnCreate(): void
    {
        $this->container->replace(ToolsInterface::class, $this->makeToolsWithEcho());

        $agent = $this->make(Agent::class, [
                        'profile' => $this->testProfile(tools: ['echo']),
            ]);

        $this->assertEqualsCanonicalizing(['echo'], $this->agentProp($agent, 'profileTools'));
    }

    protected function makeToolsWithEcho(): Tools
    {
        return $this->make(Tools::class, [
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'pong'),
                ...$this->builtinToolConfig(),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);
    }

    protected function makeToolsWithEchoAndSecret(): Tools
    {
        return $this->make(Tools::class, [
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'pong'),
                'secret' => StubTool::config('secret', 'Secret', static fn (): string => 'hidden'),
                ...$this->builtinToolConfig(),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);
    }

    protected function eventDispatcher(): TestEventDispatcher
    {
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);

        return $dispatcher;
    }

    public function testLoadRestoresMessagesFromSession(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $filesystem = $this->container->get(FilesystemInterface::class);
        $dir = $this->threadLeafDir('chat-1');
        $filesystem->mkdir($dir);
        $seed = Completion::of(UserMessage::of('hello'), new AssistantMessage('hi'));
        $filesystem->write(
            $dir . '/conversation.jsonl',
            Json::stringify(Turn::of($seed->completeId, [$seed], TurnStatus::Completed)) . "\n",
        );
        $filesystem->write(
            $dir . '/state.json',
            Json::stringify([
                'class' => Agent::class,
                'profileId' => 'host',
                'skills' => [],
                'segments' => [],
                'subseq' => 10001,
                'subagents' => [],
            ]) . "\n",
        );

        $agent = $this->make(Agent::class, [
                        'thread' => $this->threadCreate('chat-1'),
        ]);

        $this->assertSame('chat-1', $agent->threadId());
        $messages = $this->flattenWireMessages($this->threadLoad('chat-1')->completions());
        $this->assertSame('hello', $messages[0]->wire()[0]['text']);
    }

    public function testFormAskSuspendsToolReplyInterruptThenContinuesOnToolReply(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall(
            'form_ask',
            [
                'id' => 'pick',
                'fields' => [[
                    'name' => 'choice',
                    'label' => 'Pick',
                    'type' => 'radio',
                    'options' => ['a', 'b'],
                ]],
            ],
            'call_form_1',
        );
        $stub->responses[] = StubLlm::assistant('Got your pick.');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, []);
        $before = count($stub->requests);
        $this->runForTest($agent, UserMessage::of('ask form'));

        $this->assertSame($before + 1, count($stub->requests), 'form_ask must pause without a follow-up model step');
        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Suspended, $turn->status);
        $this->assertInstanceOf(\Switon\Ai\Suspension\ToolReplyInterrupt::class, $turn->interrupt);
        $this->assertSame('call_form_1', $turn->interrupt->callId);
        $this->assertSame('pick', $turn->interrupt->arguments['id'] ?? null);

        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);
        $interrupted = $dispatcher->getEventsByClass(\Switon\Ai\Event\TurnSuspended::class);
        $this->assertNotEmpty($interrupted);
        $last = $interrupted[array_key_last($interrupted)];
        $this->assertInstanceOf(\Switon\Ai\Suspension\ToolReplyInterrupt::class, $last->interrupt);
        $this->assertSame('call_form_1', $last->interrupt->callId);
        $this->assertSame('pick', $last->interrupt->arguments['id'] ?? null);

        $this->runForTest($agent, \Switon\Ai\Suspension\ToolReplyContinuation::of('call_form_1', [
            'outcome' => 'answered',
            'id' => 'pick',
            'values' => ['choice' => 'a'],
        ]));

        $this->assertSame($before + 2, count($stub->requests));
        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Completed, $turn->status);
        $this->assertNull($turn->interrupt);
        $this->assertSame('Got your pick.', $this->lastAssistantContent($agent));
    }

    public function testUserCancelDuringStreamingKeepsTheReply(): void
    {
        $stub = new StubLlm();
        $cancelTrigger = null;
        $stub->responses[] = static function (
            Thread $thread,
            Message $input,
            Charter $charter,
        ) use (&$cancelTrigger): Completion {
            $cancelTrigger?->push(true);

            return StubLlm::assistant('partial answer');
        };
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, []);
        $cancelTrigger = $this->armExternalCancel($agent);

        $error = $this->tryRun($agent, UserMessage::of('go'));
        $this->assertNotNull($error);
        $this->assertInstanceOf(RunCancelledException::class, $error);

        $this->assertNull($this->interruptCompletion($agent, 'cancelled'));
        $this->assertSame(TurnStatus::Completed, $agent->thread()->lastTurn()->status);
        $this->assertSame('partial answer', $this->lastAssistantContent($agent));
        $this->assertContains('go', array_map(
            static fn ($message): string => $message instanceof UserMessage ? (string) $message->text() : '',
            $this->threadMessages($agent),
        ));
        $this->pumpUntilCancelDrained($agent);
    }

    public function testUserCancelWithEmptyReplyKeepsTheReply(): void
    {
        $stub = new StubLlm();
        $cancelTrigger = null;
        $stub->responses[] = static function (
            Thread $thread,
            Message $input,
            Charter $charter,
        ) use (&$cancelTrigger): Completion {
            $cancelTrigger?->push(true);

            $completion3 = Completion::of(UserMessage::of(''), new AssistantMessage(''));
            $completion3->completeId = 'stub';
            $completion3->finishReason = 'stop';
            $completion3->usage = new TokenUsage();
            return $completion3;
        };
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, []);
        $cancelTrigger = $this->armExternalCancel($agent);

        $error = $this->tryRun($agent, UserMessage::of('go'));
        $this->assertNotNull($error);
        $this->assertInstanceOf(RunCancelledException::class, $error);

        $this->assertNull($this->interruptCompletion($agent, 'cancelled'));
        $turn = $agent->thread()->lastTurn();
        $this->assertSame(TurnStatus::Completed, $turn->status);
        $this->assertInstanceOf(AssistantMessage::class, $turn->lastCompletion()->output);
        $this->assertSame('', $turn->lastCompletion()->output->content);
        $this->assertContains('go', array_map(
            static fn ($message): string => $message instanceof UserMessage ? (string) $message->text() : '',
            $this->threadMessages($agent),
        ));
        $this->pumpUntilCancelDrained($agent);
    }

    public function testUserCancelWithReadonlyToolsKeepsAskAndWritesStopSignal(): void
    {
        $stub = new StubLlm();
        /** @var Agent|null $agentRef */
        $agentRef = null;
        $completion2 = Completion::of(UserMessage::of(''), new AssistantMessage('', [
            new ToolCall('c1', 'peek', []),
        ]));
        $completion2->completeId = 'chatcmpl-ro-cancel';
        $completion2->finishReason = 'tool_calls';
        $completion2->usage = new TokenUsage();
        $stub->responses[] = $completion2;
        $stub->responses[] = StubLlm::assistant('should not continue');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'peek' => StubTool::config('peek', 'Readonly', static function () use (&$agentRef): string {
                    $agent = $agentRef;
                    Coroutine::create(static function () use ($agent): void {
                        $agent?->cancel();
                    });

                    return 'peek-ok';
                }, readonly: true),
            ],
        ]));

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['peek']),
        ]);
        $agentRef = $agent;

        $error = $this->tryRun($agent, UserMessage::of('look around'));
        $this->assertInstanceOf(RunCancelledException::class, $error);

        $stop = $this->interruptCompletion($agent, 'cancelled');
        $this->assertNotNull($stop);
        $this->assertInstanceOf(AssistantAbortSignal::class, $stop->output);
        $this->assertSame('The user stopped the run.', $stop->output->body);
        $this->assertContains('look around', array_map(
            static fn ($message): string => $message instanceof UserMessage ? (string) $message->text() : '',
            $this->threadMessages($agent),
        ));
        $this->assertNotEmpty($agent->thread()->turns);
    }

    public function testUserCancelWithSideEffectToolsWritesStopSignal(): void
    {
        $stub = new StubLlm();
        /** @var Agent|null $agentRef */
        $agentRef = null;
        $completion = Completion::of(UserMessage::of(''), new AssistantMessage('', [
            new ToolCall('c1', 'write_stub', []),
        ]));
        $completion->completeId = 'chatcmpl-se-cancel';
        $completion->finishReason = 'tool_calls';
        $completion->usage = new TokenUsage();
        $stub->responses[] = $completion;
        $stub->responses[] = StubLlm::assistant('should not continue');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'write_stub' => StubTool::config('write_stub', 'Write', static function () use (&$agentRef): string {
                    $agent = $agentRef;
                    Coroutine::create(static function () use ($agent): void {
                        $agent?->cancel();
                    });

                    return 'wrote';
                }),
            ],
        ]));

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['write_stub']),
        ]);
        $agentRef = $agent;

        $error = $this->tryRun($agent, UserMessage::of('please write'));
        $this->assertInstanceOf(RunCancelledException::class, $error);

        $stop = $this->interruptCompletion($agent, 'cancelled');
        $this->assertNotNull($stop);
        $this->assertInstanceOf(AssistantAbortSignal::class, $stop->output);
        $this->assertSame('The user stopped the run.', $stop->output->body);
        $this->assertContains('please write', array_map(
            static fn ($message): string => $message instanceof UserMessage ? (string) $message->text() : '',
            $this->threadMessages($agent),
        ));
        $this->assertNotEmpty(array_filter(
            $this->threadMessages($agent),
            static fn ($message): bool => $message instanceof ToolMessage,
        ));
    }

    public function testDeliverySettledDispatchedWhenModelStepThrowsAfterDeliveryStarted(): void
    {
        $stub = new StubLlm();
        $stub->throwOnce = new RuntimeException('stub LLM down');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class);
        $error = $this->tryRun($agent, UserMessage::of('go'));
        $this->assertNotNull($error);

        $dispatcher = $this->eventDispatcher();
        $this->assertCount(1, $dispatcher->getEventsByClass(DeliveryStarted::class));
        $settled = $dispatcher->getEventsByClass(DeliverySettled::class);
        $this->assertCount(1, $settled);
        $this->assertSame(TurnStatus::Suspended, $settled[0]->turn->status);
    }

    public function testShutdownSubagentUnregistersWhenChildShutdownThrows(): void
    {
        $main = $this->testAgent();
        $leaf = 't99998';
        $child = $this->container->make(ThrowOnShutdownAgent::class, [
            'profile' => $this->container->get(ProfilesInterface::class)->get('lite'),
            'thread' => $this->threadCreate($main->thread()->id . '.' . $leaf),
        ]);
        $this->registerChildForTest($main, $child);

        try {
            $this->shutdownChildForTest($main, $leaf);
            $this->fail('Expected shutdown failure.');
        } catch (Exception $e) {
            $this->assertStringContainsString('shutdown failed', $e->getMessage());
        }

        $this->assertArrayNotHasKey($leaf, $this->liveChildren($main));
    }
}
