<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Integration;

use Switon\Thread\Threads;
use Switon\Core\Strings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Ai\Event\StepResponded;
use Switon\Agency\Tests\Fixtures\BlockingTool;
use Switon\Agency\Tests\Fixtures\RecordingRootAgent;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Ai\Signal;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;
use Switon\Core\FilesystemInterface;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message\UserMessage;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Agency\Agent;
use Switon\Ai\AgentInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Ai\Message\ToolMessage;
use Switon\Tooling\Event\ToolStarted;
use Switon\Ai\ToolResult;

/**
 * End-to-end session lifecycle: {@see RecordingAgentManager::mint()} → host run →
 * {@see task_deliver} delegation → load + {@see Agent::start()} on disk-backed threads.
 */
#[CoversClass(RecordingAgentManager::class)]
#[CoversClass(Agent::class)]
#[Group('coroutine')]
class AgentSessionIntegrationTest extends TestCase
{
    protected string $storageRoot = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir() . '/switon-agent-int-' . bin2hex(random_bytes(8));
        mkdir($this->storageRoot, 0777, true);
        $this->bindThreadStorageRoot($this->storageRoot);
    }

    protected function tearDown(): void
    {
        if ($this->storageRoot !== '' && is_dir($this->storageRoot)) {
            $this->removeTree($this->storageRoot);
        }

        parent::tearDown();
    }

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

    public function testCreateRunDelegateResumeHappyPath(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall(
            'task_deliver',
            [
                'agent' => 'lite',
                'message' => 'Say OK',
            ],
            'call_assign',
            null,
        );
        $stub->responses[] = $this->stubTaskFinish('delegated-OK');
        $stub->responses[] = StubLlm::assistant('noted finish');
        $this->container->set(LlmInterface::class, $stub);

        $manager = $this->container->get(RecordingAgentManager::class);
        $host = $manager->mint([], RecordingRootAgent::class);
        $this->bindProfileOverride($host, $this->profileVariant($host->profile(), [
            'tools' => [...$host->profile()->tools, 'block'],
        ]));
        $this->assertInstanceOf(RecordingRootAgent::class, $host);
        $threadId = $host->threadId();

        $this->deliverForTest($host, UserMessage::of('delegate this'));
        $this->pumpUntil(
            fn (): bool => ($host->lastDeliveredTaskEvent('task_finish')['body'] ?? null) === 'delegated-OK',
            5.0,
        );
        $finish = $host->lastDeliveredTaskEvent('task_finish');
        $this->assertNotNull($finish);
        $this->assertMatchesRegularExpression('/^t[1-9]\d{4,6}$/', (string) ($finish['thread_id'] ?? ''));

        $filesystem = $this->container->get(FilesystemInterface::class);
        $hostDir = $this->threadLeafDir($threadId);
        $this->assertTrue($filesystem->exists($hostDir . '/state.json'));
        $this->assertNotEmpty($this->persistedMessages($threadId));

        $host->shutdown();
        $manager->stopTrackedAgents();

        $resumed = $this->startRoot($this->threadLeafDir($threadId));
        $this->assertInstanceOf(Agent::class, $resumed);
        $this->assertSame($threadId, $resumed->threadId());
        $this->assertSame([], $this->liveChildren($resumed));
        $this->assertArrayNotHasKey('subagents', $this->threadLoadState($threadId));

        $this->assertStringContainsString('delegate this', $this->transcriptText($threadId));

        $stub->responses = [StubLlm::assistant('resumed reply')];
        $this->deliverForTest($resumed, UserMessage::of('still here?'));
        $this->pumpUntil(
            fn (): bool => str_contains($this->lastAssistantContent($resumed), 'resumed reply'),
            5.0,
        );
        $this->assertStringContainsString('resumed reply', $this->lastAssistantContent($resumed));
        $this->assertNotEmpty($this->persistedMessages($threadId));
    }

    public function testResumeSealsHungTurnWithoutRevivingDelegatedTask(): void
    {
        $manager = $this->container->get(RecordingAgentManager::class);
        $host = $manager->mint([], RecordingRootAgent::class);
        $threadId = $host->threadId();
        $worker = $this->spawnDelegated($host, 'lite');

        $completion = Completion::of(ToolMessage::of([
                new ToolResult(json_encode([
                    'status' => 'delivered',
                    'payload' => [
                        'task_id' => 'task-pending',
                        'thread_id' => $this->leafOf($worker),
                    ],
                ], JSON_THROW_ON_ERROR), 'call-task', 'task_deliver'),
            ]), new AssistantMessage('delegated'));
        $completion->completeId = 'host-hung-resume';
        $completion->step = 1;
        $this->threadAppend($host->thread(), $completion);

        $resumed = $this->startRoot($this->threadLeafDir($threadId));
        $this->assertSame([], $this->liveChildren($resumed));

        $host->shutdown();
        $resumed->shutdown();
        $manager->stopTrackedAgents();
    }

    public function testCreateMintedThreadSurvivesFreshManagerResume(): void
    {
        $manager = $this->container->get(RecordingAgentManager::class);
        $host = $manager->mint([]);
        $threadId = $host->threadId();

        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('first run');
        $this->container->set(LlmInterface::class, $stub);

        $this->deliverForTest($host, UserMessage::of('hello'));
        $this->waitForSteps($host, 1);

        $host->shutdown();
        $manager->stopTrackedAgents();

        $resumed = $this->startRoot($this->threadLeafDir($threadId));
        $this->assertSame('host', $resumed->profile()->id);
        $this->assertStringContainsString('orchestrator', $resumed->profile()->prompt);
        $this->assertSame('hello', $this->persistedMessages($threadId)[0]->wire()[0]['text'] ?? null);
        $this->assertStringContainsString('first run', $this->lastAssistantContent($resumed));
    }

    protected function host(): RecordingRootAgent
    {
        $host = $this->make(RecordingRootAgent::class);
        $this->bindProfileOverride($host, $this->profileVariant($host->profile(), [
            'tools' => [...$host->profile()->tools, 'block'],
        ]));

        return $host;
    }

    protected function assertToolStarted(string $name): void
    {
        $names = array_map(
            static fn (ToolStarted $event): string => $event->call->name,
            $this->eventDispatcher()->getEventsByClass(ToolStarted::class),
        );
        $this->assertContains($name, $names);
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

        $this->fail('Timed out waiting for ' . $expected . ' completer steps.');
    }

    protected function eventDispatcher(): TestEventDispatcher
    {
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);

        return $dispatcher;
    }

    protected function hasSessionResumedNotice(Agent $host): bool
    {
        foreach ($this->threadMessages($host) as $message) {
            if ($message instanceof \Switon\Ai\Signal\SessionResumedSignal) {
                return true;
            }
        }

        return false;
    }

    protected function transcriptText(string $threadId): string
    {
        $path = $this->threadLeafDir($threadId) . '/transcript.md';
        if (!is_file($path)) {
            $path = $this->threadLeafDir($threadId) . '/transcript.txt';
        }
        if (!is_file($path)) {
            return '';
        }

        return (string) file_get_contents($path);
    }

    protected function removeTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $items = scandir($root);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $root . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
                continue;
            }
            unlink($path);
        }

        rmdir($root);
    }
}
