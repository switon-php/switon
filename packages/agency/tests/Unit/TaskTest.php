<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Agency\Tool\TaskDeliverTool;
use Switon\Agency\Toolkit\AsToolToolkit;
use Switon\Agency\DeliverAck;
use Switon\Agency\Exception;
use Switon\Ai\Event\StepResponded;
use Switon\Tooling\Event\ToolStarted;
use Switon\Agency\Thread\TaskFinishSignal;
use Switon\Agency\Tests\Fixtures\BlockingTool;
use Switon\Agency\Tests\Fixtures\RecordingRootAgent;
use Switon\Agency\Tests\Fixtures\StubTool;
use Switon\Agency\Tests\Fixtures\DelegatedAgentWithCreate;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Core\ClockInterface;
use Switon\Core\Json;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\AsToolResult;
use Switon\Ai\LlmInterface;
use Switon\Ai\Completion;
use Switon\Ai\Message;
use Switon\Ai\ToolCall;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\TurnStatus;
use Switon\Ai\Message\UserMessage;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Agency\Agent;
use Switon\Ai\AgentInterface;
use Switon\Ai\Event\DeliveryStarted;
use Switon\Thread\ThreadsInterface;
use Switon\Eventing\EventDispatcherInterface as EventingEventDispatcherInterface;
use Switon\Ai\Thread;

#[CoversClass(TaskDeliverTool::class)]
#[CoversClass(AsToolToolkit::class)]
#[Group('coroutine')]
class TaskTest extends TestCase
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

    protected function host(): RecordingRootAgent
    {
        $host = $this->make(RecordingRootAgent::class);
        $this->bindProfileOverride($host, $this->profileVariant($host->profile(), [
            'tools' => [...$host->profile()->tools, 'block'],
        ]));

        return $host;
    }

    public function testParentAssignStartsChild(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = $this->stubTaskFinish('OK');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->host();

        $result = $this->invokeTool($host, new ToolCall(
            'c2',
            'task_deliver',
            ['agent' => 'lite', 'message' => 'Say OK'],
        ));

        $this->assertSame(DeliverAck::STATUS, $result['status'] ?? null);
        $this->assertSame(DeliverAck::MESSAGE, $result['message'] ?? null);
        $attrs = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $this->assertNotEmpty($attrs['task_id'] ?? null);
        $threadId = (string) ($attrs['thread_id'] ?? '');
        $this->assertMatchesRegularExpression('/^t[1-9]\d{4,6}$/', $threadId);
        $this->pumpUntil(function () use ($host, $threadId): bool {
            if ($host->lastDeliveredTaskEvent('task_finish') !== null) {
                return true;
            }

            return isset($this->liveChildren($host)[$threadId]);
        });
        $child = $this->liveChildren($host)[$threadId] ?? null;
        if ($child !== null) {
            $this->assertTrue($this->isTaskOpen($child));
        }
        $this->pumpUntil(fn (): bool => $host->lastDeliveredTaskEvent('task_finish') !== null);
        $this->assertSame([], $this->liveChildren($host));
    }

    public function testChildSubmitDeliversToParent(): void
    {
        $host = $this->host();
        $child = $this->spawnDelegated($host, 'lite');
        $this->openTaskForTest($child, 'test-task');

        $this->finishAssignmentWithAssistantText($child, '/path/result.php');

        $delivered = $host->lastDeliveredTaskEvent('task_finish');
        $this->assertNotNull($delivered);
        $this->assertSame($this->leafOf($child), $delivered['thread_id'] ?? null);
        $this->assertSame('test-task', $delivered['task_id'] ?? null);
        $this->assertSame($this->leafOf($child), $delivered['thread_id'] ?? null);
        $this->assertSame('completed', $delivered['status'] ?? null);
        $this->assertSame('work', $delivered['task'] ?? null);
        $this->assertSame('/path/result.php', $delivered['body'] ?? null);
        $this->assertArrayHasKey('timeSpent', $delivered);
        $this->assertGreaterThanOrEqual(0, $delivered['timeSpent']);
        $this->assertFalse($this->isTaskOpen($child), 'finish closes the child task');
        $this->assertArrayNotHasKey('worker', $this->liveChildren($host), 'finish retires child');
    }

    public function testChildFailDeliversToParent(): void
    {
        $host = $this->host();
        $child = $this->spawnDelegated($host, 'lite');
        $this->openTaskForTest($child, 'test-task');

        $this->finishTaskForTest($child, 'blocked by missing file', 'blocked');
        $this->assertFalse($this->isTaskOpen($child));

        $delivered = $host->lastDeliveredTaskEvent('task_finish');
        $this->assertNotNull($delivered);
        $this->assertSame('blocked', $delivered['status'] ?? null);
        $this->assertSame('blocked by missing file', $delivered['body'] ?? null);
    }

    public function testSubAgentSingleStepBudgetFinishesTaskForParent(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('single-step worker reply');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'loop' => StubTool::config('loop', 'Loop', static fn (): string => 'again'),
            ],
        ]));

        $host = $this->host();
        $child = $this->spawnDelegated($host, 'lite');
        $this->bindProfileOverride($child, $this->profileVariant($child->profile(), [
            'tools' => ['loop'],
            'maxSteps' => 1,
        ]));
        $this->openTaskForTest($child, 'test-task');

        $this->deliverSubForTest($child, UserMessage::of('summarize'));

        $this->assertFalse($this->isTaskOpen($child));

        $delivered = $host->lastDeliveredTaskEvent('task_finish');
        $this->assertNotNull($delivered);
        $this->assertSame('completed', $delivered['status'] ?? null);
        $this->assertSame('single-step worker reply', $delivered['body'] ?? null);
    }

    public function testSubAgentWorkerToolsExcludeDispatch(): void
    {
        $host = $this->host();
        $child = $this->spawnDelegated($host, 'lite');

        $tools = $this->agentProp($child, 'profileTools');
        $this->assertNotContains('task_deliver', $tools);
    }

    public function testLiteWorkerProfileTools(): void
    {
        $host = $this->host();
        $child = $this->make(Agent::class, [
            'profileId' => 'lite',
            'parent' => $host,
        ]);

        $tools = $this->agentProp($child, 'profileTools');
        $this->assertContains('echo', $tools);
        $this->assertNotContains('task_deliver', $tools);
    }

    public function testTaskAgentFinishClearsTasking(): void
    {
        $host = $this->host();
        $child = $this->spawnDelegated($host, 'lite');
        $this->openTaskForTest($child, 'test-task');

        $this->finishAssignmentWithAssistantText($child, '/path/done.php');

        $delivered = $host->lastDeliveredTaskEvent('task_finish');
        $this->assertNotNull($delivered);
        $this->assertSame($this->leafOf($child), $delivered['thread_id'] ?? null);
        $this->assertSame('completed', $delivered['status'] ?? null);
        $this->assertSame('/path/done.php', $delivered['body'] ?? null);
        $this->assertFalse($this->isTaskOpen($child));
    }

    public function testTaskDeliverRejectsDelegatedCaller(): void
    {
        $host = $this->host();
        $child = $this->spawnDelegated($host, 'lite');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('task_deliver is root-only; use a mounted agent_* tool');

        $this->runTool($this->make(TaskDeliverTool::class), 'task_deliver', [
            'agent' => 'lite',
            'message' => 'Do leaf work',
        ], $this->toolRuntimeHost($child));
    }

    public function testRootReceivesTaskRunResultSynchronously(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('leaf-done');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->host();
        $result = null;
        $result = $this->invokeTool($host, new ToolCall(
            'call_1',
            'agent_lite',
            ['message' => 'Do leaf work'],
        ));
        $this->assertSame('leaf-done', $result);
        $completions = $this->threadCompletions($host);
        $toolSteps = array_values(array_filter(
            $completions,
            static fn (Completion $completion): bool => $completion->input instanceof ToolMessage,
        ));
        $this->assertNotEmpty($toolSteps);
        $toolResult = $toolSteps[0]->input->results[0];
        $this->assertSame('leaf-done', $toolResult->content);
        $this->assertInstanceOf(AsToolResult::class, $toolResult);
        $this->assertNotSame('', $toolResult->call->threadId);
        $this->assertSame('lite', $toolResult->call->agent);
        $this->assertNotSame('', $toolResult->call->model);
        $this->assertSame('agent_lite', $toolResult->name);
        $this->assertNotNull($toolResult->turn);
        $this->assertCount(1, $toolResult->turn->completions);
        $this->assertSame('Do leaf work', $toolResult->turn->firstCompletion()->input->wire()[0]['text']);
        $this->assertSame('leaf-done', $toolResult->turn->lastCompletion()->output->content);
        $this->assertSame(1, $toolResult->turn->totals->llmCalls);
        $this->assertSame(0, $toolResult->turn->totals->toolCalls);
        $this->assertNotNull($toolResult->turn->totals->usage);
        $this->assertGreaterThanOrEqual(0.0, $toolResult->turn->totals->llmWall);
        $this->assertSame([], $this->liveChildren($host));
        $this->assertNull($host->lastDeliveredTaskEvent('task_finish'));
    }

    public function testSyncAsToolSurfacesStepBudgetExhausted(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('loop', callId: 'call_budget');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'loop' => StubTool::config('loop', 'Loop', static fn (): string => 'again'),
                ...$this->builtinToolConfig(),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]));

        $profiles = $this->container->get(\Switon\Agency\ProfilesInterface::class);
        $this->setTestProfile($this->profileVariant($profiles->get('lite'), [
            'tools' => ['loop'],
            'maxSteps' => 1,
        ]));

        $host = $this->host();
        $this->bindProfileOverride($host, $this->withAsTool($host->profile(), 'agent_lite'));

        $result = $this->invokeTool($host, new ToolCall(
            'call_budget_host',
            'agent_lite',
            ['message' => 'loop until the budget ends'],
        ));

        $this->assertSame('Agent step budget is exhausted.', $result);
    }

    public function testSubAgentReceivesGrandchildResultSynchronously(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('agent_lite', [
            'message' => 'Do leaf work',
        ]);
        $stub->responses[] = StubLlm::assistant('leaf-done');
        $stub->responses[] = StubLlm::assistant('mid used leaf result');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->host();
        $mid = $this->make(DelegatedAgentWithCreate::class, [
            'profileId' => 'lite',
            'parent' => $host,
        ]);
        $this->bindProfileOverride($mid, $this->withAsTool($mid->profile(), 'agent_lite'));
        $this->registerChildForTest($host, $mid);
        $this->openTaskForTest($mid, 'mid-task');

        $this->deliverSubForTest($mid, UserMessage::of('assign work to leaf'));

        $toolSteps = array_values(array_filter(
            $this->threadCompletions($mid),
            static fn (Completion $completion): bool => $completion->input instanceof ToolMessage,
        ));
        $this->assertCount(1, $toolSteps);
        $toolResult = $toolSteps[0]->input->results[0];
        $this->assertSame('leaf-done', $toolResult->content);
        $this->assertInstanceOf(AsToolResult::class, $toolResult);
        $this->assertSame('mid used leaf result', $this->lastAssistantContent($mid));
        $this->assertSame([], $this->liveChildren($mid));
    }

    public function testWorkerDeliveryDoesNotExposeTaskFinishTool(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = $this->stubTaskFinish('/path/file.php');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->host();
        $child = $this->make(Agent::class, [
            'profileId' => 'lite',
            'parent' => $host,
        ]);
        $this->openTaskForTest($child, 'test-task');
        $this->deliverSubForTest($child, UserMessage::of('do work'));

        $dispatcher = $this->container->get(EventingEventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);
        $started = array_values(array_filter(
            $dispatcher->getEventsByClass(DeliveryStarted::class),
            static fn (DeliveryStarted $event): bool => $event->sender->threadId() === $child->threadId(),
        ));
        $this->assertNotEmpty($started);
        $charter = $started[0]->sender->charter();
        $this->assertArrayNotHasKey('task_finish', $charter->tools);
    }

    public function testParentContinuesAfterTaskDeliverAck(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall(
            'task_deliver',
            [
                'agent' => 'lite',
                'message' => 'Say OK',
                '.caption' => 'Assign worker',
            ],
        );
        $stub->responses[] = StubLlm::assistant('OK');
        $stub->responses[] = StubLlm::assistant('Started the scan; you can keep chatting.');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->host();

        $before = count($stub->requests);
        $this->deliverForTest($host, UserMessage::of('delegate via task'));
        $this->waitForSteps($host, 2);
        $this->assertGreaterThan(
            $before + 1,
            count($stub->requests),
            'Host must follow task_deliver with another LLM step',
        );
        $this->assertToolStarted('task_deliver');
        $this->assertSame(
            'Started the scan; you can keep chatting.',
            $this->lastAssistantContent($host),
        );

        $completions = $this->threadCompletions($host);
        $this->assertGreaterThanOrEqual(2, count($completions));
        $toolInput = $completions[1]->input;
        $this->assertInstanceOf(ToolMessage::class, $toolInput);
        $this->assertStringContainsString(
            'delivered',
            Json::text($toolInput->results[0]->content),
            'Host must continue the loop with task_deliver tool_result as the next step input',
        );
    }

    public function testWorkerAssistantTextCompletesAssignment(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('I will look for it…');
        $stub->responses[] = StubLlm::assistant('unexpected follow-up');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->host();
        $child = $this->spawnDelegated($host, 'lite');
        $this->openTaskForTest($child, 'test-task');

        $this->deliverSubForTest($child, UserMessage::of('do work'));
        $this->waitForSteps($child, 1);
        $this->assertStepCount(1, $child);
        $this->assertFalse($this->isTaskOpen($child));
        $delivered = $host->lastDeliveredTaskEvent('task_finish');
        $this->assertNotNull($delivered);
        $this->assertSame('I will look for it…', $delivered['body'] ?? null);
    }

    public function testRegisteredWorkerAssistantTextCompletesAssignment(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('working on it…');
        $stub->responses[] = StubLlm::assistant('unexpected follow-up');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->host();
        $child = $this->spawnDelegated($host, 'lite');
        $this->openTaskForTest($child, 'test-task');

        $this->deliverSubForTest($child, UserMessage::of('do work'));
        $this->assertStepCount(1, $child);
        $this->assertFalse($this->isTaskOpen($child));
        $delivered = $host->lastDeliveredTaskEvent('task_finish');
        $this->assertNotNull($delivered);
        $this->assertSame('working on it…', $delivered['body'] ?? null);
    }

    public function testRegisteredSubAssistantTextEndsRun(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('Found it — should not continue.');
        $stub->responses[] = StubLlm::assistant('unexpected follow-up');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->host();
        $child = $this->spawnDelegated($host, 'lite');
        $this->openTaskForTest($child, 'test-task');

        $this->deliverSubForTest($child, UserMessage::of('do work'));
        $this->assertStepCount(1, $child);
        $this->assertFalse($this->isTaskOpen($child));
        $delivered = $host->lastDeliveredTaskEvent('task_finish');
        $this->assertNotNull($delivered);
        $this->assertSame('Found it — should not continue.', $delivered['body'] ?? null);
    }

    public function testAssignReportsDeliverAck(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = $this->stubTaskFinish('inner');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->host();

        $result = $this->invokeTool($host, new ToolCall(
            'c1',
            'task_deliver',
            ['agent' => 'lite', 'message' => 'hello'],
        ));

        $this->assertSame(DeliverAck::STATUS, $result['status'] ?? null);
        $this->assertSame(DeliverAck::MESSAGE, $result['message'] ?? null);
        $attrs = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $this->assertNotEmpty($attrs['task_id'] ?? null);
        $this->assertMatchesRegularExpression('/^t[1-9]\d{4,6}$/', (string) ($attrs['thread_id'] ?? ''));
        $this->assertSame('lite', $attrs['agent'] ?? null);
        $this->assertNotSame('', $attrs['model'] ?? '');
        $this->assertArrayNotHasKey('content', $result);
    }

    public function testAssignRegistersActiveAndSubmitRemoves(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('block', ['key' => 'hold worker'], 'b1', null);
        $stub->responses[] = $this->stubTaskFinish('done');
        $this->container->set(LlmInterface::class, $stub);
        $states = $this->container->get(ThreadsInterface::class);

        $host = $this->host();
        BlockingTool::arm('hold worker');

        $assigned = $this->invokeTool($host, new ToolCall(
            'c1',
            'task_deliver',
            [
                'agent' => 'lite',
                'message' => 'do it',
            ],
        ));

        $this->assertSame('delivered', $assigned['status'] ?? null);
        $leaf = (string) ($assigned['payload']['thread_id'] ?? '');
        $this->pumpUntil(fn (): bool => isset($this->liveChildren($host)[$leaf]));
        BlockingTool::waitUntilBlocked('hold worker');
        $worker = $this->requireSubagent($host, $leaf);
        $this->assertTrue($this->isTaskOpen($worker));
        $hostState = $this->loadThread($host->thread()->datadir, $host->thread()->id)->state;
        $this->assertArrayNotHasKey('tasking', $hostState);

        // invokeTool left an open turn; seal it then seed a committed slice for resume closure.
        $open = $this->agentThread($host)->lastTurn();
        $open->status = TurnStatus::Completed;
        $states->commit($this->agentThread($host));
        $completion = Completion::of(UserMessage::of('assign'), new AssistantMessage('delegated'));
        $completion->completeId = 'host-assign-resume';
        $completion->step = 1;
        $completion->landedAt = $this->container->get(ClockInterface::class)->now();
        $this->agentThread($host)->lastTurn()->completions[] = $completion;
        $states->saveTurn($this->agentThread($host));

        $resumed = $this->startRoot($host->thread()->datadir);
        $this->assertSame([], $this->liveChildren($resumed));
        $resumedState = $this->loadThread($resumed->thread()->datadir, $resumed->thread()->id)->state;
        $this->assertArrayNotHasKey(
            'subagents',
            $resumedState,
        );
        $resumed->shutdown();
        BlockingTool::release('hold worker');
    }

    public function testClosingTaskFinishRetiresChild(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = $this->stubTaskFinish('ACK');
        $stub->responses[] = StubLlm::assistant('parent saw ACK');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->host();
        $child = $this->spawnDelegated($host, 'lite');

        $this->openTaskForTest($child, 'test-task');
        $this->deliverSubForTest($child, UserMessage::of('Remember XYZZY-1'));

        $this->assertArrayNotHasKey('dialog', $this->liveChildren($host));
        $this->pumpUntil(fn (): bool => ($host->lastDeliveredTaskEvent('task_finish')['body'] ?? null) === 'ACK');
    }

    protected function eventDispatcher(): TestEventDispatcher
    {
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);

        return $dispatcher;
    }

    protected function assertStepCount(int $expected, ?AgentInterface $agent = null): void
    {
        // Task-ending tools return after StepResponded; count those steps.
        $events = $this->eventDispatcher()->getEventsByClass(StepResponded::class);
        if ($agent !== null) {
            $sid = $agent->threadId();
            $events = array_values(array_filter(
                $events,
                static fn (StepResponded $event): bool => $event->sender->threadId() === $sid,
            ));
        }
        $this->assertCount($expected, $events);
    }

    protected function waitForSteps(AgentInterface $agent, int $expected): void
    {
        for ($i = 0; $i < 2000; $i++) {
            $events = $this->eventDispatcher()->getEventsByClass(StepResponded::class);
            $sid = $agent->threadId();
            $count = count(array_filter(
                $events,
                static fn (StepResponded $event): bool => $event->sender->threadId() === $sid,
            ));
            if ($count >= $expected) {
                return;
            }
            \Swoole\Coroutine::sleep(0.001);
        }

        $this->fail('waitForSteps timed out');
    }

    protected function assertToolStarted(string $name): void
    {
        $names = array_map(
            static fn (ToolStarted $event): string => $event->call->name,
            $this->eventDispatcher()->getEventsByClass(ToolStarted::class),
        );
        $this->assertContains($name, $names);
    }

}
