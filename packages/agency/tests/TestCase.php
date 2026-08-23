<?php

declare(strict_types=1);

namespace Switon\Agency\Tests;

use ReflectionProperty;
use Switon\Agency\Agent;
use Switon\Ai\AgentInterface;
use Switon\Ai\Letter;
use Switon\Agency\Delivery;
use Switon\Agency\Tool\TaskDeliverTool;
use Switon\Agency\ProfilesInterface;
use Switon\Ai\Profile;
use Switon\Ai\TurnStatus;
use Switon\Thread\ThreadsInterface;
use Switon\Ai\Signal\AssistantAbortSignal;
use Switon\Ai\Signal\UserAbortSignal;
use Switon\Agency\Thread\TaskAssignMessage;
use Switon\Agency\Tests\Fixtures\BlockingTool;
use Switon\Agency\Tests\Fixtures\EchoTool;
use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Agency\Tests\Fixtures\RecordingRootAgent;
use Switon\Tooling\ToolsInterface;
use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Tooling\Tools as ToolingTools;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Ai\LlmInterface;
use Switon\Ai\Completion;
use Switon\Ai\Thread;
use Switon\Ai\Envelope;
use Switon\Ai\Message;
use Switon\Ai\Message\SystemMessage;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolInterface;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Core\Runtime;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use ReflectionMethod;
use Switon\Core\Json;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Charter;
use Switon\Ai\Model;
use Switon\Ai\Turn;
use Switon\Ai\ToolResult;
use InvalidArgumentException;
use JsonSerializable;
use Throwable;

use function array_filter;
use function array_values;
use function basename;
use function is_array;
use function is_string;
use function iterator_to_array;

abstract class TestCase extends AgentContainerTestCase
{
    /** Unit tests must fail fast — never inherit production run() default of 300s. */
    /** @var array<string, Turn> leaf thread id => parent dispatch meeting for the next task delivery */
    protected array $stagedTaskFrom = [];
    /** @var array<string, string> leaf thread id => assign id for the next task delivery */
    protected array $stagedTaskId = [];

    #[Autowired] protected ClockInterface $clock;

    /**
     * PHPUnit 12 made {@see \PHPUnit\Framework\TestCase::runTest()} private; wrap via {@see invokeTestMethod()}.
     * setUp/tearDown stay outside the scheduler; agent create/deliver must happen in the test method body.
     *
     * @param array<mixed> $testArguments
     */
    protected function invokeTestMethod(string $methodName, array $testArguments): mixed
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required for switon/agency');
        }

        Runtime::setCoroutineEnabled(true);
        $prevHooks = \Swoole\Runtime::getHookFlags();
        \Swoole\Runtime::setHookFlags(defined('SWOOLE_HOOK_SLEEP') ? SWOOLE_HOOK_SLEEP : $prevHooks);
        BlockingTool::drainAll();
        $result = null;
        $error = null;
        try {
            \Swoole\Coroutine\run(function () use ($methodName, $testArguments, &$result, &$error): void {
                try {
                    $result = parent::invokeTestMethod($methodName, $testArguments);
                } catch (Throwable $e) {
                    $error = $e;
                } finally {
                    BlockingTool::drainAll();
                    // Live/eval may App::setContainer() to a harness container; drain that too.
                    if (function_exists('liveShutdownOpenAgents')) {
                        liveShutdownOpenAgents();
                    }
                    $manager = $this->container->get(RecordingAgentManager::class);
                    if ($manager instanceof RecordingAgentManager) {
                        $manager->stopTrackedAgents();
                    }
                    $this->drainCoroutineWorkers();
                }
            });
        } finally {
            BlockingTool::drainAll();
            \Swoole\Runtime::setHookFlags($prevHooks);
            Runtime::setCoroutineEnabled(false);
        }

        if ($error !== null) {
            throw $error;
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @template T of object
     *
     * @param class-string<T> $name
     *
     * @phpstan-return T
     */
    /**
     * Segment grants name tools from other packages. Register a stand-in when that package is absent
     * so charter assembly can be asserted without requiring it.
     */
    protected function ensureTools(string ...$names): void
    {
        $tools = $this->container->get(ToolsInterface::class);
        $standIn = new EchoTool();
        foreach ($names as $name) {
            $tools->addTool($name, $standIn);
        }
    }

    public function make(string $name, array $parameters = []): mixed
    {
        if ($name === Agent::class || is_subclass_of($name, Agent::class)) {
            if (($parameters['parent'] ?? null) !== null) {
                $parent = $parameters['parent'];
                unset($parameters['parent']);

                if (isset($parameters['profile'])) {
                    $profile = $parameters['profile'];
                    if (!$profile instanceof Profile) {
                        throw new InvalidArgumentException('Delegated agent profile must be a Profile instance.');
                    }
                    unset($parameters['profile']);
                    $profileId = trim((string) ($parameters['profileId'] ?? $profile->id));
                    if ($profileId !== $profile->id) {
                        throw new InvalidArgumentException(sprintf(
                            'Delegated agent profileId "%s" must match profile id "%s".',
                            $profileId,
                            $profile->id,
                        ));
                    }
                    $this->setTestProfile($profile);
                } else {
                    $profileId = trim((string) ($parameters['profileId'] ?? ''));
                }
                unset($parameters['profileId']);

                if ($profileId === '') {
                    throw new InvalidArgumentException('Delegated agent create requires profileId.');
                }

                $leaf = $parameters['leaf'] ?? $parameters['threadId'] ?? null;
                unset($parameters['leaf'], $parameters['threadId']);
                if (!is_string($leaf) || $leaf === '') {
                    $leaf = null;
                } elseif (str_contains($leaf, '.')) {
                    [, $leaf] = explode('.', $leaf, 2);
                }

                unset($parameters['thread']);

                return $this->invokeSpawnWorker($parent, $profileId, $leaf);
            }

            return $this->container->get(RecordingAgentManager::class)->mint($parameters, $name);
        }

        return $this->container->make($name, $parameters);
    }

    /** Load a saved root thread, `make` {@see Agent}, and `start()` it. */
    protected function startRoot(string $datadir): Agent
    {
        $thread = $this->loadThread($datadir);
        $state = $thread->state;
        /** @var Agent $agent */
        $agent = $this->container->make(Agent::class, [
            'thread' => $thread,
            'workspace' => (string) ($state['workspace'] ?? ''),
            'profile' => $this->container->get(ProfilesInterface::class)->get($state['profileId']),
        ]);
        $agent->start();
        $manager = $this->container->get(RecordingAgentManager::class);
        if ($manager instanceof RecordingAgentManager) {
            $manager->track($agent);
        }

        return $agent;
    }

    protected function testAgent(string $name = 'test'): Agent
    {
        return $this->make(Agent::class);
    }

    /** @return list<\Switon\Ai\Completion> Persisted working conversation ({@see ThreadsInterface::load()}). */
    protected function threadTranscript(string $id): array
    {
        $completions = [];
        $thread = $this->loadThread($this->threadDir($id), basename($this->threadDir($id)));
        foreach ($thread->turns as $turn) {
            foreach ($turn->completions as $completion) {
                $completions[] = $completion;
            }
        }

        return $completions;
    }

    /**
     * @param list<Turn> $turns
     * @param array<string, mixed> $state
     */
    protected function makeThread(string $id, array $turns = [], array $state = []): Thread
    {
        return new Thread($id, '', turns: $turns, state: $state, datadir: $this->threadDir($id));
    }

    protected function withAgentStorageDir(AgentInterface $agent, Thread $thread): Thread
    {
        if ($thread->datadir !== null) {
            return $thread;
        }

        return new Thread(
            $thread->id,
            turns: $thread->turns,
            state: $thread->state,
            datadir: $this->threadDir($thread->id !== '' ? $thread->id : $agent->threadId()),
        );
    }

    /** @return list<Message> Wire-order transcript for content assertions after {@see Agent::run()}. */
    protected function persistedMessages(string $threadId): array
    {
        return $this->flattenWireMessages(
            $this->loadThread($this->threadDir($threadId))->completions(),
        );
    }

    /** @return list<Message> */
    protected function persistedAgentMessages(AgentInterface $agent): array
    {
        if ($agent->parent() !== null) {
            return $this->threadMessages($agent);
        }

        return $this->persistedMessages($agent->threadId());
    }

    /** @return array<string, AgentInterface> Spawned children whose inbox is still open. */
    protected function liveChildren(AgentInterface $parent): array
    {
        assert($parent instanceof Agent);

        $children = (new ReflectionProperty(Agent::class, 'children'))->getValue($parent);
        if (!$children instanceof \WeakMap) {
            return [];
        }
        $inbox = new ReflectionProperty(Agent::class, 'inbox');
        $open = [];
        foreach ($children as $child => $leaf) {
            if ($child instanceof Agent && $inbox->getValue($child) !== null) {
                $open[$leaf] = $child;
            }
        }

        return $open;
    }

    protected function taskDeliverTool(): TaskDeliverTool
    {
        return $this->make(TaskDeliverTool::class);
    }

    protected function isTaskOpen(AgentInterface $agent): bool
    {
        if ($agent->parent() === null) {
            return false;
        }

        $parent = $agent->parent();
        if ($parent === null) {
            return false;
        }

        return isset($this->liveChildren($parent)[$this->leafOf($agent)]);
    }

    protected function leafOf(AgentInterface $agent): string
    {
        return pathinfo($agent->threadId(), PATHINFO_EXTENSION);
    }

    protected function dispatchCompletionForTest(string $anchor = 'test-dispatch'): Completion
    {
        $completion = Completion::of(UserMessage::of('dispatch'));
        $completion->completeId = $anchor;

        return $completion;
    }

    protected function dispatchTurnForTest(string $anchor = 'test-dispatch', string $model = 'main'): Turn
    {
        return Turn::of($anchor, status: TurnStatus::Running, model: $model);
    }

    /**
     * Agent for direct tool / event dispatch in tests, with a dispatch turn in place.
     *
     * Guidance: tools that need "the turn that requested this call" read it from
     * `thread()->lastTurn()`, so tests must give the agent an open turn first.
     */
    protected function toolRuntimeHost(AgentInterface $agent, ToolCall|string $call = '', ?Turn $from = null): AgentInterface
    {
        unset($call);
        if ($from !== null) {
            $agent->thread()->turns[] = $from;

            return $agent;
        }

        if ($agent->thread()->turns === []) {
            $completion = $this->dispatchCompletionForTest();
            $agent->thread()->turns[] = Turn::of($completion->completeId, [$completion], TurnStatus::Running, 'main');
        }

        return $agent;
    }

    protected function openTaskForTest(
        AgentInterface $agent,
        string $taskId,
        ?Turn $from = null,
    ): void {
        $from ??= $this->dispatchTurnForTest($taskId, $this->modelForTest($agent));
        $this->stagedTaskFrom[$this->leafOf($agent)] = $from;
        $this->stagedTaskId[$this->leafOf($agent)] = $taskId;
    }

    protected function registerChildForTest(AgentInterface $parent, AgentInterface $child): void
    {
        assert($parent instanceof Agent);
        assert($child instanceof Agent);
        if ((new ReflectionProperty(Agent::class, 'inbox'))->getValue($child) === null) {
            $child->start();
        }
        $childrenProperty = new ReflectionProperty(Agent::class, 'children');
        $children = $childrenProperty->getValue($parent);
        if (!$children instanceof \WeakMap) {
            $children = new \WeakMap();
            $childrenProperty->setValue($parent, $children);
        }
        $children[$child] = $this->leafOf($child);
    }

    protected function shutdownChildForTest(AgentInterface $parent, string $leaf): void
    {
        $child = $this->liveChildren($parent)[$leaf] ?? null;
        $child?->shutdown();
    }

    /** Look up a live delegated worker by leaf id from the parent's spawned children. */
    protected function requireSubagent(AgentInterface $parent, string $leafId): AgentInterface
    {
        $child = $this->liveChildren($parent)[$leafId] ?? null;
        $this->assertInstanceOf(AgentInterface::class, $child, 'Expected registered task worker "' . $leafId . '"');
        $this->assertNotNull($child->parent());

        return $child;
    }

    /** Create a registered delegated agent for tests (bypasses tool allowlists; mirrors TaskDeliverTool without dispatch). */
    protected function spawnDelegated(AgentInterface $parent, string $profileId, ?string $threadId = null): AgentInterface
    {
        if ($parent->thread()->turns === []) {
            $seed = Completion::of(UserMessage::of('assigned'));
            $seed->step = 1;
            $parent->thread()->turns[] = Turn::of(
                $seed->completeId,
                [$seed],
                TurnStatus::Completed,
                model: 'main',
            );
        }

        if ($threadId !== null && $threadId !== '' && str_contains($threadId, '.')) {
            [, $threadId] = explode('.', $threadId, 2);
        }

        $child = $this->invokeSpawnWorker($parent, $profileId, $threadId);
        $this->registerChildForTest($parent, $child);

        return $child;
    }

    protected function invokeSpawnWorker(
        AgentInterface $parent,
        string $profileId,
        ?string $threadId = null,
    ): AgentInterface {
        assert($parent instanceof Agent);
        $child = $parent->spawn($this->container->get(ProfilesInterface::class)->get($profileId), $threadId);
        $manager = $this->container->get(RecordingAgentManager::class);
        if ($manager instanceof RecordingAgentManager) {
            $manager->track($child);
        }

        return $child;
    }

    /** @return mixed */
    protected function agentProp(AgentInterface $agent, string $property): mixed
    {
        if ($property === 'profileTools') {
            return $agent->profile()->tools;
        }

        if ($property === 'segments') {
            if ($agent->parent() === null) {
                return $this->agentState($agent)['segments'] ?? [];
            }

            return [];
        }

        if ($property === 'subagents') {
            return $this->liveChildren($agent);
        }

        return (new ReflectionProperty(Agent::class, $property))->getValue($agent);
    }

    /**
     * Test-only profile variant: clone + field overrides (not persisted on resume).
     *
     * @param array<string, mixed> $overrides
     */
    protected function profileVariant(Profile $config, array $overrides = []): Profile
    {
        $variant = clone $config;

        foreach ($overrides as $key => $value) {
            $variant->$key = $value;
        }

        return $variant;
    }

    /**
     * Same-id runtime override for tests (not persisted; use {@see Agent::switchProfile()} for registry id changes).
     * Registers the override in {@see ProfilesInterface} so {@see Agent::charter()} resolves the same instance.
     */
    protected function bindProfileOverride(Agent $agent, Profile $config): void
    {
        $this->setTestProfile($config);
        (new ReflectionProperty(Agent::class, 'profile'))->setValue($agent, $config);
    }

    protected function profileWithTools(Profile $config, string ...$tools): Profile
    {
        $merged = $config->tools;
        foreach ($tools as $tool) {
            if (!in_array($tool, $merged, true)) {
                $merged[] = $tool;
            }
        }

        return $this->profileVariant($config, ['tools' => $merged]);
    }

    /** Expose a mounted {@see $wire} as-tool on this profile's tool list. */
    protected function withAsTool(Profile $config, string $wire = 'agent_lite'): Profile
    {
        if (in_array($wire, $config->tools, true)) {
            return $config;
        }

        return $this->profileVariant($config, [
            'tools' => [...$config->tools, $wire],
        ]);
    }

    protected function invokeTool(AgentInterface $agent, ToolCall $call, string $model = 'main'): mixed
    {
        $tools = $this->container->get(ToolsInterface::class);
        $ownedTurn = false;
        if ($agent->thread()->turns === [] || $agent->thread()->lastTurn()->isSettled()) {
            // Standalone harness call: short sealed turn so idle task_finish can deliver after.
            $dispatch = Completion::of(UserMessage::of(''));
            $turn = \Switon\Ai\Turn::of($dispatch->completeId, [$dispatch], TurnStatus::Running, $model);
            $agent->thread()->turns[] = $turn;
            $ownedTurn = true;
        } else {
            $turn = $agent->thread()->lastTurn();
        }
        $dispatch = $turn->lastCompletion();
        $schema = $tools->get($call->name);
        $this->assertNotNull($schema);
        $outcome = $tools->call($agent, $call, $schema);
        if ($outcome instanceof \Switon\Ai\Suspension\Interrupt) {
            $turn->interrupt ??= $outcome;
            $turn->status = TurnStatus::Suspended;
            if ($outcome instanceof \Switon\Ai\Suspension\ToolReplyInterrupt) {
                return [
                    'name' => $outcome->name,
                    'arguments' => $outcome->arguments,
                ];
            }

            return $outcome instanceof JsonSerializable ? $outcome->jsonSerialize() : [];
        }

        $turn->completions[] = Completion::of(ToolMessage::of([$outcome]));
        if ($ownedTurn) {
            $turn->status = TurnStatus::Completed;
        }

        return $this->toolResultPayload($outcome);
    }

    protected function toolResultPayload(ToolResult $result): mixed
    {
        $content = $result->content;
        if ($content instanceof JsonSerializable) {
            return $content->jsonSerialize();
        }
        if (is_array($content)) {
            return $content;
        }
        if (!is_string($content) || $content === '') {
            return $content;
        }

        try {
            $payload = Json::parse($content);
        } catch (Throwable) {
            return $content;
        }

        if (!is_array($payload)) {
            return $content;
        }

        return $payload;
    }

    protected function agentThread(AgentInterface $agent): Thread
    {
        return $agent->thread();
    }

    /**
     * Test stand-in for the agent loop: open a turn when the tip is missing or sealed, place
     * `$completion` on it, then checkpoint. Production callers ({@see Agent::runDelivery()})
     * create the turn.
     */
    protected function threadAppend(Thread $thread, Completion $completion): void
    {
        $now = $this->container->get(ClockInterface::class)->now();
        $completion->landedAt = $now;
        if ($thread->turns === [] || $thread->lastTurn()->isSettled()) {
            $turn = Turn::of($completion->completeId, [$completion], TurnStatus::Running);
            $turn->openedAt = $now;
            $thread->turns[] = $turn;
        } elseif ($thread->lastTurn()->completions === [] || $thread->lastTurn()->lastCompletion() !== $completion) {
            $thread->lastTurn()->completions[] = $completion;
        }
        $this->threadStore()->saveTurn($thread);
    }

    /** @return list<Message> In-memory working set (use {@see persistedMessages()} after {@see Agent::run()}). */
    protected function threadMessages(AgentInterface $agent): array
    {
        return $this->flattenWireMessages($this->agentThread($agent)->completions());
    }

    protected function lastAssistantContent(AgentInterface $agent): string
    {
        foreach (array_reverse($this->threadMessages($agent)) as $message) {
            if ($message instanceof AssistantMessage && (string) $message->content !== '') {
                return (string) $message->content;
            }
        }

        return '';
    }

    /** @return list<Completion> */
    protected function threadCompletions(AgentInterface $agent): array
    {
        $completions = [];
        foreach ($this->agentThread($agent)->turns as $turn) {
            foreach ($turn->completions as $completion) {
                $completions[] = $completion;
            }
        }

        return $completions;
    }

    /** Meeting id for a landed step (Completion no longer carries turnId). */
    protected function turnIdFor(AgentInterface $agent, Completion $completion): string
    {
        foreach ($this->agentThread($agent)->turns as $turn) {
            foreach ($turn->completions as $step) {
                if ($step === $completion || $step->completeId === $completion->completeId) {
                    return $turn->turnId;
                }
            }
        }

        return '';
    }

    /** Meeting id from persisted conversation for a step completeId. */
    protected function persistedTurnIdForCompleteId(string $datadir, string $completeId, string $threadId = ''): string
    {
        foreach ($this->loadThread($datadir, $threadId)->turns as $turn) {
            foreach ($turn->completions as $step) {
                if ($step->completeId === $completeId) {
                    return $turn->turnId;
                }
            }
        }

        return '';
    }

    protected function firstUserCompletionInput(AgentInterface $agent): string
    {
        foreach ($this->threadCompletions($agent) as $completion) {
            $input = $completion->input;
            if ($input instanceof UserMessage && $input->text() !== null) {
                return $input->text();
            }
        }

        return '';
    }

    /** @param list<Message> $request */
    protected function requestIncludesToolResultNamed(array $request, string $toolName): bool
    {
        foreach ($request as $message) {
            if (!$message instanceof ToolMessage) {
                continue;
            }

            foreach ($message->results as $result) {
                if ($result->name === $toolName) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<Message> $request */
    protected function toolResultContentNamed(array $request, string $toolName): string
    {
        foreach ($request as $message) {
            if (!$message instanceof ToolMessage) {
                continue;
            }

            foreach ($message->results as $result) {
                if ($result->name === $toolName) {
                    return Json::text($result->wire());
                }
            }
        }

        return '';
    }

    /**
     * @param class-string<Agent> $name
     * @param array<string, mixed> $parameters
     */
    protected function setAgent(string $name, array $parameters): void
    {
        $parameters['class'] ??= $name;
        $this->container->set($name, $parameters);
    }

    /**
     * @param list<Message> $messages
     */
    protected function seedMessages(AgentInterface $agent, array $messages): void
    {
        $thread = $this->withAgentStorageDir(
            $agent,
            new Thread($agent->threadId(), $this->turnsFromMessages(array_values($messages))),
        );
        $property = new ReflectionProperty(Agent::class, 'thread');
        $property->setValue($agent, $thread);
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<Turn>
     */
    protected function turnsFromMessages(array $messages): array
    {
        $completions = [];
        $buffer = null;

        foreach ($messages as $message) {
            if ($message instanceof AssistantMessage) {
                $input = $buffer ?? UserMessage::of('');
                $completions[] = Completion::of($input, $message);
                $buffer = null;
                continue;
            }

            $buffer = $message;
        }

        if ($completions === []) {
            return [];
        }

        return [Turn::of('seed', $completions, TurnStatus::Completed)];
    }

    protected function startBlockedRun(AgentInterface $agent, StubLlm $stub, string $message = 'hold'): string
    {
        $key = 'block_' . bin2hex(random_bytes(8));
        BlockingTool::arm($key);
        array_unshift($stub->responses, StubLlm::assistantWithToolCall(
            'block',
            ['key' => $key],
            'call_' . $key,
        ));
        $this->deliverForTest($agent, UserMessage::of($message));
        $this->pumpUntil(static function () use ($key): bool {
            $channel = BlockingTool::enteredChannel($key);

            return $channel !== null && $channel->length() > 0;
        });
        BlockingTool::waitUntilBlocked($key, 0.05);

        return $key;
    }

    protected function releaseBlockedRun(string $key): void
    {
        BlockingTool::release($key);
    }

    /**
     * Wait for a sync {@see \Switon\Agency\Tests\Fixtures\RecordingRootAgent} deliver spy (no parent worker).
     *
     * @return array<string, mixed>|null
     */
    protected function awaitDeliveredTaskFinish(
        RecordingRootAgent $agent,
        ?string $from = null,
        float $timeoutSeconds = 2.0,
    ): ?array {
        $found = null;
        $this->pumpUntil(function () use ($agent, $from, &$found): bool {
            foreach ($agent->deliveredTaskEvents() as $event) {
                if (($event['signal'] ?? '') !== 'task_finish') {
                    continue;
                }
                if ($from !== null && ($event['thread_id'] ?? '') !== $from) {
                    continue;
                }
                $found = $event;

                return true;
            }

            return false;
        }, $timeoutSeconds);

        return $found;
    }

    /** @param array<string, mixed> $arguments */
    protected function runTool(
        ToolInterface $tool,
        string $name,
        array $arguments = [],
        ?AgentInterface $agent = null,
    ): mixed {
        return $tool->call(
            $agent ?? \Switon\Agency\Tests\Fixtures\StubToolAgent::in(),
            new ToolCall(name: $name),
            $arguments,
        );
    }

    /**
     * Arm {@see AgentInterface::cancel()} on a coroutine outside the inbox worker.
     *
     * Worker-context {@see Coroutine::create()} cancel races delivery completion; push
     * {@see $trigger} when cancel should start, then {@see pumpUntilCancelDrained()}.
     *
     * @return Channel<mixed> push to start cancel
     */
    protected function armExternalCancel(AgentInterface $agent): Channel
    {
        $trigger = new Channel(1);
        Coroutine::create(static function () use ($agent, $trigger): void {
            $trigger->pop();
            $agent->cancel();
        });

        return $trigger;
    }

    protected function pumpUntilCancelDrained(AgentInterface $agent, float $timeoutSeconds = 1.0): void
    {
        $cancel = new ReflectionProperty(Agent::class, 'cancel');
        $this->pumpUntil(static function () use ($agent, $cancel): bool {
            if (!$agent instanceof Agent) {
                return true;
            }

            return $cancel->getValue($agent) === null;
        }, $timeoutSeconds);
    }

    protected function deliverForTest(AgentInterface $agent, Letter $message): void
    {
        $this->ensureDelegatedTasking($agent);
        $from = $this->stagedTaskFrom[$this->leafOf($agent)] ?? null;
        $taskId = $this->stagedTaskId[$this->leafOf($agent)] ?? '';
        unset($this->stagedTaskFrom[$this->leafOf($agent)], $this->stagedTaskId[$this->leafOf($agent)]);
        if ($from !== null && $taskId !== '') {
            if ($message instanceof TaskAssignMessage) {
                $message->taskId = $taskId;
            } else {
                $message = $message instanceof UserMessage
                    ? TaskAssignMessage::of('', $message->parts, $taskId)
                    : TaskAssignMessage::of((string) ($message->content ?? ''), taskId: $taskId);
            }
        }
        $model = $this->modelForTest($agent);
        if ($from !== null && ($parent = $agent->parent()) !== null && $agent instanceof Agent) {
            $envelope = Envelope::of($message, $model, from: $from);
            $envelope->onSettled = (new ReflectionMethod(TaskDeliverTool::class, 'settle'))->invoke(
                $this->taskDeliverTool(),
                $parent,
                $from,
                $envelope->letter,
            );
            $agent->deliver($envelope);

            return;
        }
        $agent->deliver(Envelope::of($message, $model, from: $from));
    }

    protected function runForTest(AgentInterface $agent, Letter $message): ?Turn
    {
        return $agent->run(Envelope::of($message, $this->modelForTest($agent)));
    }

    protected function runDeliveryForTest(
        AgentInterface $agent,
        Letter $message,
        ?string $model = null,
    ): ?Turn {
        $method = new ReflectionMethod(Agent::class, 'runDelivery');
        $outcome = $method->invoke($agent, new Delivery(
            Envelope::of($message, $model ?? $this->modelForTest($agent)),
            $this->clock->microtime(),
        ));
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $agent->thread()->turns === [] ? null : $agent->thread()->lastTurn();
    }

    /**
     * Queue a sync run behind an in-flight delivery; returns the {@see Agent::run()} outcome channel.
     *
     * Uses the inbox directly: calling {@see Agent::run()} from a child coroutine while
     * {@see Agent::shutdown()} runs in another triggers an ext-swoole segfault (exit 255).
     *
     * @return \Swoole\Coroutine\Channel<Throwable|null>
     */
    protected function enqueueSyncRunForTest(AgentInterface $agent, Letter $message): \Swoole\Coroutine\Channel
    {
        $done = new \Swoole\Coroutine\Channel(1);
        $inbox = (new ReflectionProperty(Agent::class, 'inbox'))->getValue($agent);
        $inbox->push(new Delivery(
            Envelope::of($message, $this->modelForTest($agent)),
            $this->clock->microtime(),
            $done,
        ));

        return $done;
    }

    protected function deliverSubForTest(AgentInterface $agent, Letter $message): void
    {
        $this->deliverForTest($agent, $message);
        $this->pumpUntil(fn (): bool => !$this->isTaskOpen($agent));
    }

    protected function ensureDelegatedTasking(AgentInterface $agent, string $taskId = 'test-task'): void
    {
        if ($agent->parent() === null || isset($this->stagedTaskFrom[$this->leafOf($agent)])) {
            return;
        }

        $this->openTaskForTest($agent, $taskId);
    }

    protected function modelForTest(AgentInterface $agent): string
    {
        return $agent->profile()->model;
    }

    protected function tryRun(AgentInterface $agent, Letter $message): ?Throwable
    {
        try {
            $this->runForTest($agent, $message);

            return null;
        } catch (Throwable $e) {
            return $e;
        }
    }

    /** Yield until only the test coroutine remains (after {@see RecordingAgentManager::stopTrackedAgents()}). */
    protected function drainCoroutineWorkers(float $timeoutSeconds = 0.2): void
    {
        BlockingTool::drainAll();
        $manager = $this->container->get(RecordingAgentManager::class);
        if ($manager instanceof RecordingAgentManager) {
            $manager->stopTrackedAgents();
        }

        $deadline = microtime(true) + max(0.01, $timeoutSeconds);
        while (microtime(true) < $deadline) {
            if ((\Swoole\Coroutine::stats()['coroutine_num'] ?? 1) <= 1) {
                return;
            }

            \Swoole\Coroutine::sleep(0.001);
        }
    }

    /**
     * Yield until {@see $predicate} is true (background {@see AgentInterface::deliver()} only).
     *
     * @param callable(): bool $predicate
     */
    protected function pumpUntil(callable $predicate, float $timeoutSeconds = 1.0): void
    {
        $deadline = microtime(true) + max(0.01, $timeoutSeconds);
        while (microtime(true) < $deadline) {
            if ($predicate()) {
                return;
            }

            \Swoole\Coroutine::sleep(0.001);
        }

        $this->fail('pumpUntil timed out');
    }

    /** @return array<string, mixed>|null */
    protected function taskEventFromMessage(Message $message): ?array
    {
        if (!$message instanceof \Switon\Agency\Thread\TaskFinishSignal) {
            return null;
        }

        return $message->fields + [
            'signal' => $message->name,
            'body' => $message->body,
        ];
    }

    /** @return array<string, mixed>|null */
    protected function taskFinishInConversation(AgentInterface $agent, ?string $threadId = null): ?array
    {
        foreach ($this->threadMessages($agent) as $message) {
            $decoded = $this->taskEventFromMessage($message);
            if ($decoded !== null
                && ($decoded['signal'] ?? '') === 'task_finish'
                && ($threadId === null || ($decoded['thread_id'] ?? '') === $threadId)
            ) {
                return $decoded;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    protected function taskFinishesInConversation(AgentInterface $agent): array
    {
        $events = [];
        foreach ($this->threadMessages($agent) as $message) {
            $decoded = $this->taskEventFromMessage($message);
            if ($decoded !== null && ($decoded['signal'] ?? '') === 'task_finish') {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    /**
     * Sub-agent assignment result: final assistant text (runtime delivers task_finish signal).
     */
    protected function stubTaskFinish(
        string $message,
    ): Completion {
        return StubLlm::assistant($message);
    }

    protected function isSubagentThread(Thread $thread): bool
    {
        return (is_string($thread->datadir) && str_contains($thread->datadir, '/subagents/'))
            || str_contains($thread->id, '.');
    }

    /**
     * Route stub LLM output: sub-agent worker → final assistant text; host → fallback completion.
     *
     * @return callable(Thread, Message, \Switon\Ai\Charter): Completion
     */
    protected function stubRouteSubagentTaskFinishOr(
        string $finishBody,
        Completion $hostFallback,
    ): callable {
        return function (
            Thread $thread,
            Message $input,
            Charter $charter,
        ) use (
            $finishBody,
            $hostFallback,
        ): Completion {
            if ($this->isSubagentThread($thread)) {
                return $this->stubTaskFinish($finishBody);
            }

            return $hostFallback;
        };
    }

    /**
     * Abort row on the turn.
     *
     * Guidance: `'cancelled'` is the {@see AssistantAbortSignal} written when the tip was unfinished.
     * Other kinds (`exception` / `budget_exceeded`) only exist on the assistant side.
     */
    protected function interruptCompletion(AgentInterface $agent, ?string $kind = null): ?Completion
    {
        $completions = $this->threadCompletions($agent);
        if ($kind === null || $kind === AssistantAbortSignal::KIND_CANCELLED) {
            foreach ($completions as $completion) {
                if ($completion->input instanceof UserAbortSignal) {
                    return $completion;
                }
            }
        }
        if ($kind === AssistantAbortSignal::KIND_CANCELLED) {
            $kind = null;
        }

        foreach ($completions as $completion) {
            $input = $completion->input;
            $output = $completion->output;
            if (!$output instanceof AssistantAbortSignal) {
                continue;
            }

            // Tool-batch closures and first-step UserMessage failures both land abort signals.
            if (!($input instanceof ToolMessage || $input instanceof UserMessage)) {
                continue;
            }

            if ($kind === null || $output->kind === $kind) {
                return $completion;
            }
        }

        return null;
    }

    protected function finishTaskForTest(AgentInterface $child, string $message, string $status = 'completed'): void
    {
        $from = $this->stagedTaskFrom[$this->leafOf($child)] ?? $this->dispatchTurnForTest('test-task', $this->modelForTest($child));
        $taskId = $this->stagedTaskId[$this->leafOf($child)] ?? 'test-task';
        unset($this->stagedTaskFrom[$this->leafOf($child)], $this->stagedTaskId[$this->leafOf($child)]);

        $letter = TaskAssignMessage::of('', taskId: $taskId);
        $parent = $child->parent();
        $this->assertInstanceOf(AgentInterface::class, $parent);
        $finish = new ReflectionMethod(TaskDeliverTool::class, 'finish');
        try {
            $finish->invoke(
                $this->taskDeliverTool(),
                $child,
                $parent,
                $from,
                $letter,
                $this->clock->microtime(),
                $message,
                $status,
            );
        } finally {
            $child->shutdown();
        }
    }

    protected function finishAssignmentWithAssistantText(AgentInterface $child, string $message): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant($message);
        $this->container->set(LlmInterface::class, $stub);
        $this->deliverSubForTest($child, UserMessage::of('work'));
    }

    /**
     * Tooling property defaults + `extra.switon.tools`.
     *
     * @return array<string, class-string>
     */
    protected function builtinToolConfig(): array
    {
        /** @var array<string, class-string> $defaults */
        $defaults = (new ReflectionProperty(ToolingTools::class, 'tools'))->getDefaultValue();

        /** @var ComposerExtraInterface $composerExtra */
        $composerExtra = $this->container->get(ComposerExtraInterface::class);

        return array_merge($defaults, iterator_to_array($composerExtra->collect('switon.tools')));
    }

}
