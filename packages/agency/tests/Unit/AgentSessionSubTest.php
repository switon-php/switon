<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Agent;
use Switon\Ai\Event\DeliveryStarted;
use Switon\Agency\Tests\Fixtures\StubTool;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Ai\TurnStatus;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message;
use Switon\Ai\Turn;
use Switon\Ai\Message\UserMessage;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Ai\Signal\AssistantSignal;
use Switon\Ai\Signal\UserSignal;
use Switon\Agency\Thread\TaskFinishSignal;
use Switon\Thread\ThreadsInterface;
use Switon\Core\ClockInterface;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use ReflectionMethod;
use Switon\Ai\Thread;

#[CoversClass(Agent::class)]
#[Group('coroutine')]
class AgentSessionSubTest extends TestCase
{
    protected function setUpContainer(): void
    {
        parent::setUpContainer();
        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo'),
                'bash' => StubTool::config('bash', 'Shell'),
                'read' => StubTool::config('read', 'Read'),
                ...$this->builtinToolConfig(),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);
    }

    public function testSessionSubRunsDelegatedTask(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = $this->stubTaskFinish('Lite done');
        $stub->responses[] = StubLlm::assistant('parent ack');
        $this->container->set(LlmInterface::class, $stub);

        $main = $this->make(Agent::class, [
        ]);

        $lite = $this->spawnDelegated($main, 'lite');
        $this->openTaskForTest($lite, 'test-task');
        $this->deliverSubForTest($lite, UserMessage::of('Summarize logs'));

        $this->assertFalse($this->isTaskOpen($lite));
        $this->assertGreaterThanOrEqual(2, count($this->persistedAgentMessages($lite)));

        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);
        $started = array_values(array_filter(
            $dispatcher->getEventsByClass(DeliveryStarted::class),
            static fn (DeliveryStarted $event): bool => $event->sender->threadId() === $lite->threadId(),
        ));
        $this->assertCount(1, $started);
        $this->assertSame($lite->threadId(), $started[0]->sender->threadId());
    }

    public function testSessionSubSpawnsFreshAndRetiresOnFinish(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = $this->stubTaskFinish('first');
        $stub->responses[] = StubLlm::assistant('parent ack');
        $this->container->set(LlmInterface::class, $stub);

        $main = $this->make(Agent::class, [
        ]);

        $first = $this->spawnDelegated($main, 'lite');
        $this->openTaskForTest($first, 'assign-1');
        $this->deliverSubForTest($first, UserMessage::of('First'));

        $this->assertArrayNotHasKey($this->leafOf($first), $this->liveChildren($main));
        $this->assertFalse($this->isTaskOpen($first));

        $second = $this->spawnDelegated($main, 'lite', $this->leafOf($first));
        $this->assertNotSame($first, $second);
        // Same leaf continues persisted conversation after the prior worker shut down.
        $this->assertNotSame([], $second->thread()->turns);
    }

    public function testTaskDeliverStartsDistinctTurnIds(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = $this->stubTaskFinish('first');
        $stub->responses[] = StubLlm::assistant('parent ack');
        $stub->responses[] = $this->stubTaskFinish('second');
        $stub->responses[] = StubLlm::assistant('parent ack');
        $this->container->set(LlmInterface::class, $stub);

        $main = $this->make(Agent::class, []);
        $first = $this->spawnDelegated($main, 'lite');
        $this->openTaskForTest($first, 'task-a');
        $this->deliverSubForTest($first, UserMessage::of('First'));

        $second = $this->spawnDelegated($main, 'lite');
        $this->openTaskForTest($second, 'task-b');
        $this->deliverSubForTest($second, UserMessage::of('Second'));

        $firstRow = $this->threadCompletions($first)[0];
        $secondRow = $this->threadCompletions($second)[0];
        $this->assertNotSame('', $this->turnIdFor($first, $firstRow));
        $this->assertNotSame('', $this->turnIdFor($second, $secondRow));
        $this->assertNotSame($this->turnIdFor($first, $firstRow), $this->turnIdFor($second, $secondRow));
    }

    public function testSessionSubDoesNotReviveChildrenOnResume(): void
    {
        $main = $this->make(Agent::class, [
        ]);

        $first = $this->spawnDelegated($main, 'lite');
        $states = $this->container->get(ThreadsInterface::class);
        $mainState = $this->loadThread($main->thread()->datadir, $main->thread()->id)->state;
        $this->assertArrayNotHasKey(
            'subagents',
            $mainState,
        );

        $c_host_before_resume = Completion::of(ToolMessage::of([
                new ToolResult(json_encode([
                    'status' => 'delivered',
                    'payload' => [
                        'task_id' => 'task-pending',
                        'thread_id' => $this->leafOf($first),
                    ],
                ], JSON_THROW_ON_ERROR), 'call-task', 'task_deliver'),
            ]), new AssistantMessage('delegated'));
        $c_host_before_resume->completeId = 'host-before-resume';
        $c_host_before_resume->step = 1;
        $this->agentThread($main)->turns[] = Turn::of(
            'turn-before-resume',
            [$c_host_before_resume],
            TurnStatus::Running,
            'main',
        );
        $c_host_before_resume->landedAt = $this->container->get(ClockInterface::class)->now();
        $states->saveTurn($this->agentThread($main));

        $resumed = $this->startRoot($main->thread()->datadir);

        $this->assertInstanceOf(Agent::class, $resumed);
        $this->assertSame([], $this->liveChildren($resumed));
        $this->assertArrayNotHasKey(
            'subagents',
            $this->loadThread($main->thread()->datadir, $main->thread()->id)->state,
        );

        // Resume closes open tools; it never re-announces pending tasks as a new user turn.
        foreach ($resumed->thread()->turns as $turn) {
            foreach ($turn->completions as $row) {
                $this->assertNotInstanceOf(UserSignal::class, $row->input);
            }
        }
    }

    public function testResumeClosesOpenToolsWithSessionResumed(): void
    {
        $main = $this->make(Agent::class, []);
        $thread = $this->agentThread($main);
        $c_open_tool_step2 = Completion::of(UserMessage::of('go'), new AssistantMessage('', [
                new ToolCall('call_1', 'task_deliver', ['agent' => 'lite', 'message' => 'x']),
            ]));
        $c_open_tool_step2->completeId = 'open-tool-step';
        $c_open_tool_step2->step = 1;
        $thread->turns[] = \Switon\Ai\Turn::of('turn-open', [$c_open_tool_step2], TurnStatus::Running, 'main');
        $this->threadAppend($thread, $c_open_tool_step2);

        $resumed = $this->startRoot($main->thread()->datadir);
        $closure = null;
        $closed = null;
        foreach ($resumed->thread()->turns as $turn) {
            foreach ($turn->completions as $row) {
                if ($row->input instanceof ToolMessage
                    && ($row->input->results[0]->id ?? '') === 'call_1'
                ) {
                    $closure = $row;
                    $closed = $turn;
                    break 2;
                }
            }
        }
        $this->assertNotNull($closure);
        $this->assertNotSame('', $closure->completeId);
        $this->assertNotSame('open-tool-step', $closure->completeId);
        $this->assertSame(
            'turn-open',
            $this->persistedTurnIdForCompleteId($resumed->thread()->datadir, $closure->completeId, $resumed->threadId()),
        );
        $this->assertSame(2, $closure->step);
        $this->assertStringContainsString('This call was not completed.', (string) $closure->input->results[0]->content);
        $this->assertInstanceOf(\Switon\Ai\Signal\SessionResumedSignal::class, $closure->output);
        $this->assertSame('session_resumed', $closure->output->label());
        $this->assertSame(
            'Session restarted. This unfinished turn was closed.',
            $closure->output->body,
        );
        $this->assertSame([], $closure->output->fields);
        $this->assertSame(TurnStatus::Aborted, $closed->status);
    }

    public function testResumeClosesReadonlyToolBatch(): void
    {
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'read' => StubTool::config('read', 'Read', static fn (): string => 'body', readonly: true),
                'write' => StubTool::config('write', 'Write', static fn (): string => 'wrote'),
            ],
        ]));

        $main = $this->make(Agent::class, []);
        $thread = $this->agentThread($main);
        $open = Completion::of(UserMessage::of('go'), new AssistantMessage('', [
            new ToolCall('call_1', 'read', ['path' => 'a']),
            new ToolCall('call_2', 'read', ['path' => 'b']),
        ]));
        $open->completeId = 'open-reads';
        $open->step = 1;
        $thread->turns[] = Turn::of('turn-open', [$open], TurnStatus::Running, 'main');
        $this->threadAppend($thread, $open);

        $resumed = $this->startRoot($main->thread()->datadir);
        $turn = $resumed->thread()->lastTurn();

        $this->assertSame(TurnStatus::Aborted, $turn->status);
        $this->assertNull($turn->interrupt);
        $closure = $turn->lastCompletion();
        $this->assertInstanceOf(ToolMessage::class, $closure->input);
        $this->assertSame('call_1', $closure->input->results[0]->id);
        $this->assertInstanceOf(\Switon\Ai\Signal\SessionResumedSignal::class, $closure->output);
        $this->assertCount(2, $turn->completions);
    }

    public function testResumeClosesReadonlyToolBatchWhenToolRowIsOpen(): void
    {
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'read' => StubTool::config('read', 'Read', static fn (): string => 'body', readonly: true),
            ],
        ]));

        $main = $this->make(Agent::class, []);
        $thread = $this->agentThread($main);
        $assistant = Completion::of(UserMessage::of('go'), new AssistantMessage('', [
            new ToolCall('call_1', 'read', ['path' => 'a']),
        ]));
        $assistant->completeId = 'open-reads';
        $assistant->step = 1;
        $toolRow = Completion::of(ToolMessage::of([]));
        $toolRow->completeId = 'open-tool-row';
        $toolRow->step = 2;
        $thread->turns[] = Turn::of('turn-open', [$assistant], TurnStatus::Running, 'main');
        $this->threadAppend($thread, $assistant);
        $this->threadAppend($thread, $toolRow);

        $resumed = $this->startRoot($main->thread()->datadir);
        $turn = $resumed->thread()->lastTurn();

        $this->assertSame(TurnStatus::Aborted, $turn->status);
        $this->assertNull($turn->interrupt);
        $closure = $turn->lastCompletion();
        $this->assertSame('open-tool-row', $closure->completeId);
        $this->assertInstanceOf(ToolMessage::class, $closure->input);
        $this->assertSame('call_1', $closure->input->results[0]->id);
        $this->assertInstanceOf(\Switon\Ai\Signal\SessionResumedSignal::class, $closure->output);
        $this->assertCount(2, $turn->completions);
        $this->assertCount(1, $turn->completions[0]->toolCalls());
    }

    public function testResumeSkipsWhenCleanAssistantAndNoSubagents(): void
    {
        $main = $this->make(Agent::class, []);
        $this->threadAppend($this->agentThread($main), Completion::of(UserMessage::of('hi'), new AssistantMessage('hello')));
        $before = count($this->threadMessages($main));

        $resumed = $this->startRoot($main->thread()->datadir);
        $this->assertCount($before, $this->threadMessages($resumed));
    }

    public function testResumeSkipsCompletedTranscriptDelivery(): void
    {
        $main = $this->make(Agent::class, []);
        $c_c_deliver = Completion::of(ToolMessage::of([
                new ToolResult(json_encode([
                    'status' => 'delivered',
                    'payload' => ['task_id' => 'task-done', 'thread_id' => 't10001'],
                ], JSON_THROW_ON_ERROR), 'call-task', 'task_deliver'),
            ]), new AssistantMessage('delegated'));
        $c_c_deliver->completeId = 'c-deliver';
        $c_c_deliver->step = 1;
        $this->threadAppend($this->agentThread($main), $c_c_deliver);
        $c_c_finish = Completion::of(new TaskFinishSignal(['task_id' => 'task-done', 'status' => 'completed'], 'done'), new AssistantMessage('acknowledged'));
        $c_c_finish->completeId = 'c-finish';
        $c_c_finish->step = 2;
        $this->threadAppend($this->agentThread($main), $c_c_finish);
        $before = count($this->threadMessages($main));

        $resumed = $this->startRoot($main->thread()->datadir);

        $this->assertCount($before, $this->threadMessages($resumed));
    }

    public function testSessionSubKeepsSubAgentOwnTools(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('worker done');
        $this->container->set(LlmInterface::class, $stub);

        $main = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['bash']),
        ]);

        $lite = $this->spawnDelegated($main, 'lite');
        $this->deliverSubForTest($lite, UserMessage::of('Check host'));

        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);
        $started = array_values(array_filter(
            $dispatcher->getEventsByClass(DeliveryStarted::class),
            static fn (DeliveryStarted $event): bool => $event->sender->threadId() === $lite->threadId(),
        ));
        $this->assertCount(1, $started);
        $tools = $this->agentProp($started[0]->sender, 'profileTools');
        $this->assertEqualsCanonicalizing(['echo', 'block'], $tools);
        $charter = $started[0]->sender->charter();
        $this->assertNotContains('bash', array_keys($charter->tools));
    }

    public function testDelegatedAgentHasSessionStateOnCreate(): void
    {
        $main = $this->make(Agent::class, []);
        $lite = $this->spawnDelegated($main, 'lite');

        $this->assertSame([], $this->agentState($lite));
        $state = $this->agentState($lite);
        $state['scratch'] = 'cursor-1';
        $lite->thread()->state = $state;
        $this->assertSame('cursor-1', $this->agentState($lite)['scratch']);
    }

    public function testDelegatedPersistStateWritesOptionalStateJson(): void
    {
        $main = $this->make(Agent::class, []);
        $lite = $this->spawnDelegated($main, 'lite');
        $state = $this->agentState($lite);
        $state['scratch'] = 'keep-me';
        $lite->thread()->state = $state;
        $this->persistAgentState($lite);

        $datadir = $this->container->get(PathAliasInterface::class)
            ->resolve($lite->thread()->datadir);
        $this->assertTrue($this->container->get(FilesystemInterface::class)
            ->exists($datadir . '/state.json'));
        $this->assertSame(
            'keep-me',
            $this->loadThread($lite->thread()->datadir, $lite->thread()->id)->state['scratch'] ?? null,
        );
    }

    public function testDelegatedCreateLoadsPersistedStateWhenPresent(): void
    {
        $main = $this->make(Agent::class, []);
        $first = $this->spawnDelegated($main, 'lite');
        $leaf = $this->leafOf($first);
        $state = $this->agentState($first);
        $state['scratch'] = 'resume-me';
        $first->thread()->state = $state;
        $this->persistAgentState($first);

        $second = $this->spawnDelegated($main, 'lite', $leaf);
        $this->assertNotSame($first, $second);
        $this->assertSame('resume-me', $this->agentState($second)['scratch'] ?? null);
    }
}
