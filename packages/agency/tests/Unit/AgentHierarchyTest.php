<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Agent;
use Switon\Agency\Tool\TaskDeliverTool;
use Switon\Agency\Toolkit\AsToolToolkit;
use Switon\Agency\DeliverAck;
use Switon\Ai\Event\DeliveryStarted;
use Switon\Agency\Exception;
use Switon\Agency\ProfilesInterface;
use Switon\Ai\Message\UserMessage;
use Switon\Agency\Tests\Fixtures\BlockingTool;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;
use Switon\Ai\LlmInterface;
use Switon\Ai\ToolCall;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Testing\EventDispatcher;
use Switon\Ai\Exception\ToolInvalidArgumentException;
use Switon\Ai\Thread;
use function basename;

#[CoversClass(Agent::class)]
#[CoversClass(TaskDeliverTool::class)]
#[CoversClass(AsToolToolkit::class)]
#[Group('coroutine')]
class AgentHierarchyTest extends TestCase
{
    public function testSpawnSubAgentSetsParent(): void
    {
        $host = $this->make(Agent::class, [
        ]);

        $sub = $this->spawnDelegated($host, 'lite');

        $this->assertSame($host, $sub->parent());
        $this->assertSame($host, $sub->root());
        $this->assertSame($host, $host->root());
    }

    public function testSpawnSubAgentInheritsParentOnDelta(): void
    {
        $handler = static function (): void {
        };
        $host = $this->make(Agent::class, [
            'onDelta' => $handler,
        ]);

        $sub = $this->spawnDelegated($host, 'lite');

        $this->assertSame($handler, $sub->onDelta);
    }

    public function testHostAgentHasNoSubAgentIdentity(): void
    {
        $host = $this->make(Agent::class, [
        ]);

        $this->assertInstanceOf(Agent::class, $host);
        $this->assertNull($host->parent());
        $this->assertSame($host, $host->root());
    }

    public function testTaskDeliverRejectsInvalidHandleBeforeSpawn(): void
    {
        $host = $this->make(Agent::class, [
            'profile' => $this->testProfile(),
            ]);

        try {
            $this->runTool($this->make(TaskDeliverTool::class), 'task_deliver', [
                'thread_id' => 'Bad Name',
                'agent' => 'lite',
                'message' => 'Work',
            ], $this->toolRuntimeHost($host));
            $this->fail('Expected ToolInvalidArgumentException for bad thread_id.');
        } catch (ToolInvalidArgumentException $e) {
            $this->assertStringContainsString('t + ≥5 digits', $e->getMessage());
        }

        $this->assertSame([], $this->liveChildren($host));
    }

    public function testTaskDeliverContinuesIssuedLeafWithoutConversation(): void
    {
        $this->armTaskDeliverChildHold();
        $host = $this->make(Agent::class, [
            'profile' => $this->testProfile(),
        ]);
        $hostDatadir = $host->thread()->datadir;
        $state = $this->loadThread($hostDatadir)->state;
        $seq = (int) ($state['subseq'] ?? 10001);
        $leaf = 't' . $seq;
        $state['subseq'] = $seq + 1;
        $host->thread()->state = $state;
        $this->threadStore()->saveState($host->thread());
        $issued = $host->threadId() . '.' . $leaf;
        $this->assertSame('t10001', $leaf);
        $this->assertSame(10002, $this->loadThread($hostDatadir)->state['subseq'] ?? 10001);

        $ack = $this->invokeTool($host, new ToolCall(
            'call_1',
            'task_deliver',
            ['thread_id' => $leaf, 'agent' => 'lite', 'message' => 'resume'],
        ));
        $this->assertIsArray($ack);
        $this->assertSame($leaf, $ack['payload']['thread_id'] ?? null);
        $this->pumpUntil(fn (): bool => isset($this->liveChildren($host)[$leaf]));
        BlockingTool::waitUntilBlocked('hold');
        $child = $this->requireSubagent($host, $leaf);
        $this->assertSame($issued, $child->threadId());
        BlockingTool::release('hold');
        BlockingTool::drainAll();
    }

    public function testTaskDeliverRejectsHostLeafThreadId(): void
    {
        $host = $this->make(Agent::class, [
            'profile' => $this->testProfile(),
            ]);

        try {
            $this->runTool($this->make(TaskDeliverTool::class), 'task_deliver', [
                'thread_id' => $host->threadId() . '.t10001',
                'agent' => 'lite',
                'message' => 'Work',
            ], $this->toolRuntimeHost($host));
            $this->fail('Expected ToolInvalidArgumentException for host.leaf thread_id.');
        } catch (ToolInvalidArgumentException $e) {
            $this->assertStringContainsString('leaf id', $e->getMessage());
        }
    }

    public function testTaskDeliverSpawnsTLeafAndUsesLeafAsName(): void
    {
        $this->armTaskDeliverChildHold();
        $host = $this->make(Agent::class, [
            'profile' => $this->testProfile(),
        ]);
        $ack = $this->invokeTool($host, new ToolCall(
            'call_1',
            'task_deliver',
            ['agent' => 'lite', 'message' => 'ping'],
        ));
        $this->assertIsArray($ack);
        $leaf = $ack['payload']['thread_id'] ?? '';
        $this->assertMatchesRegularExpression('/^t[1-9]\d{4,6}$/', $leaf);
        $this->pumpUntil(fn (): bool => isset($this->liveChildren($host)[$leaf]));
        BlockingTool::waitUntilBlocked('hold');
        $child = $this->requireSubagent($host, $leaf);
        $this->assertSame($host->threadId() . '.' . $leaf, $child->threadId());
        BlockingTool::release('hold');
        BlockingTool::drainAll();
    }

    public function testHostAgentCoordinatorDefaults(): void
    {
        $host = $this->make(Agent::class, []);

        $this->assertSame('host', $this->agentState($host)['profileId'] ?? '');
        $this->assertArrayNotHasKey('prompts', $this->agentState($host));
        $this->assertInstanceOf(Agent::class, $host);
        $this->assertNull($host->parent());
    }

    public function testTaskDeliverMaterializesSubAgent(): void
    {
        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => [
                'block' => BlockingTool::class,
                ...$this->builtinToolConfig(),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);

        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('block', ['key' => 'hold'], 'b1', null);
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->make(Agent::class, [
            'profile' => $this->testProfile(),
            ]);

        BlockingTool::arm('hold');
        $payload = $this->invokeTool($host, new ToolCall(
            'call_1',
            'task_deliver',
            [
                'agent' => 'lite',
                'message' => 'Work',
            ],
        ));

        $attrs = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $leaf = (string) ($attrs['thread_id'] ?? '');
        $this->pumpUntil(fn (): bool => isset($this->liveChildren($host)[$leaf]));
        BlockingTool::waitUntilBlocked('hold');
        $sub = $this->requireSubagent($host, $leaf);

        $this->assertInstanceOf(Agent::class, $sub);
        $this->assertSame('lite', $sub->profile()->id);
        $this->assertSame(1, $sub->depth());
        $this->assertSame($host, $sub->parent());
        $this->assertSame($host->threadId() . '.' . $leaf, $sub->thread()->id);
        BlockingTool::release('hold');
    }

    public function testSpawnSubAgentReturnsSubAgent(): void
    {
        $host = $this->make(Agent::class, [
        ]);

        $sub = $this->spawnDelegated($host, 'lite');

        $this->assertInstanceOf(Agent::class, $sub);
    }

    public function testHostAgentDepthIsZero(): void
    {
        $host = $this->make(Agent::class, [
        ]);

        $this->assertSame(0, $host->depth());
    }

    public function testSubAgentDepthDefaultsToOne(): void
    {
        $host = $this->make(Agent::class, [
        ]);
        $sub = $this->make(Agent::class, [
            'profileId' => 'lite',
            'parent' => $host,
        ]);

        $this->assertSame(1, $sub->depth());
        $this->assertSame($host, $sub->parent());
    }

    public function testSubAgentDepthIncrementsWithNesting(): void
    {
        $host = $this->make(Agent::class, [
        ]);
        $child = $this->spawnDelegated($host, 'lite');

        $this->assertSame(1, $child->depth());
        $this->assertSame($host, $child->parent());

        $grandchild = $this->make(Agent::class, [
            'profileId' => 'lite',
            'parent' => $child,
        ]);
        $this->registerChildForTest($child, $grandchild);

        $this->assertSame(2, $grandchild->depth());
        $this->assertSame($child, $grandchild->parent());
    }

    public function testSpawnSubAgentDerivesDepthFromParent(): void
    {
        $host = $this->make(Agent::class, [
        ]);
        $child = $this->spawnDelegated($host, 'lite');

        $this->assertSame(0, $host->depth());
        $this->assertSame(1, $child->depth());

        $nested = $this->make(Agent::class, [
            'profileId' => 'lite',
            'parent' => $host,
        ]);
        $this->registerChildForTest($host, $nested);
        $grandchild = $this->spawnDelegated($nested, 'lite');

        $this->assertSame(2, $grandchild->depth());
        $this->assertSame($nested, $grandchild->parent());
    }

    public function testTaskRunFromChildSubAgentSetsGrandchildDepth(): void
    {
        $host = $this->make(Agent::class, [
        ]);
        $child = $this->make(Agent::class, [
            'profileId' => 'lite',
            'parent' => $host,
        ]);
        $this->registerChildForTest($host, $child);

        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('done');
        $this->container->set(LlmInterface::class, $stub);

        $result = $this->invokeTool($child, new ToolCall(
            'call_1',
            'agent_lite',
            ['message' => 'Work'],
        ));

        $this->assertSame('done', $result);
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(EventDispatcher::class, $dispatcher);
        $started = array_values(array_filter(
            $dispatcher->getEventsByClass(DeliveryStarted::class),
            static fn (DeliveryStarted $event): bool => $event->sender->depth() === 2,
        ));
        $this->assertCount(1, $started);
        $this->assertSame([], $this->liveChildren($child));
        $this->assertSame(1, $child->depth());
        $this->assertSame(0, $host->depth());
    }

    public function testDeliveryStartedPayload(): void
    {
        $host = $this->make(Agent::class, [
        ]);
        $child = $this->spawnDelegated($host, 'lite');

        $event = $this->deliveryStartedEvent($child, UserMessage::of('go'));

        $this->assertSame([
            'thread_id' => $child->thread()->id,
            'turn_id' => $child->thread()->lastTurn()?->turnId ?? '',
            'model' => $child->profile()->model,
            'step' => 1,
            'agent' => 'lite',
        ], $event->jsonSerialize());
    }

    public function testPersistentSubAgentSystemPromptOmitsTaskDeliveryProtocol(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('ok');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->make(Agent::class, [
        ]);
        $child = $this->spawnDelegated($host, 'lite');
        $this->deliverSubForTest($child, UserMessage::of('go'));

        $system = $stub->requests[0][0]->content;
        $this->assertStringContainsString('Signals', $system);
        $this->assertStringNotContainsString('Task delivery', $system);
        $this->assertStringNotContainsString('do not poll', $system);
        $this->assertStringNotContainsString('Sub-agent role', $system);
        $this->assertStringNotContainsString('Finish with `task_finish` only', $system);
    }

    public function testHostWithTaskToolsGetsTaskDeliveryProtocolOnly(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('ok');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->make(Agent::class, []);
        $this->runForTest($host, UserMessage::of('go'));

        $system = $stub->requests[0][0]->content;
        $this->assertStringContainsString('You are the orchestrator', $system);
        $this->assertStringContainsString('Task delivery', $system);
        $this->assertStringContainsString('[signal:task_finish]', $system);
        $this->assertStringNotContainsString('Sub-agent role', $system);
        $this->assertStringContainsString('After `task_deliver`, do not poll', $system);
    }

    public function testTaskAgentSystemPromptUsesSharedSubAgentProtocol(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = $this->stubTaskFinish('ok');
        $stub->responses[] = StubLlm::assistant('parent ack');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->make(Agent::class, [
        ]);
        $profiles = $this->container->get(ProfilesInterface::class);
        $lite = $profiles->get('lite');
        $taskProfile = $this->profileVariant($lite, [
            'prompt' => str_replace('Lite prompt.', 'task worker', $lite->prompt),
            'tools' => ['echo'],
        ]);
        $task = $this->make(Agent::class, [
            'profileId' => 'lite',
            'profile' => $taskProfile,
            'parent' => $host,
        ]);
        $this->openTaskForTest($task, 'test-task');
        $this->deliverSubForTest($task, UserMessage::of('go'));

        $system = $stub->requests[0][0]->content;
        $this->assertStringContainsString('Signals', $system);
        $this->assertStringContainsString('task worker', $system);
        $this->assertStringNotContainsString('Task delivery', $system);
        $this->assertStringNotContainsString('Sub-agent role', $system);
        $this->assertStringNotContainsString('Finish with `task_finish` only', $system);
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(EventDispatcher::class, $dispatcher);
        $started = $dispatcher->getEventsByClass(DeliveryStarted::class);
        $this->assertNotEmpty($started);
        $charter = $started[0]->sender->charter();
        $this->assertArrayNotHasKey('task_finish', $charter->tools);
    }

    /** Keep task_deliver children alive until assertions (stub empty reply would close them). */
    protected function armTaskDeliverChildHold(): void
    {
        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => [
                'block' => BlockingTool::class,
                ...$this->builtinToolConfig(),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);

        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('block', ['key' => 'hold'], 'b1', null);
        $this->container->set(LlmInterface::class, $stub);
        BlockingTool::arm('hold');
    }
}
