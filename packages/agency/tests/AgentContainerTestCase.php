<?php

declare(strict_types=1);

namespace Switon\Agency\Tests;

use Switon\Agency\Agent;
use Switon\Agency\ExtensionsInterface;
use Switon\Agency\Tests\Fixtures\MutableExtensionRegistry;
use Switon\Ai\AgentInterface;
use Switon\Ai\TurnStatus;
use Switon\Agency\PromptVariableDiscoveryInterface;
use Switon\Agency\SegmentsInterface;
use Switon\Ai\Event\DeliveryStarted;
use Switon\Ai\Profile;
use Switon\Agency\ProfileDiscovery;
use Switon\Agency\ProfileDiscoveryInterface;
use Switon\Agency\ProfilesInterface;
use Switon\Agency\Toolkit\AsToolToolkit;
use Switon\Tooling\ToolsInterface;
use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Agency\Tests\Fixtures\UnitTestAgent;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Agency\Tests\Unit\Fixtures\StubLlmProviders;
use Switon\Thread\Threads;
use Switon\Thread\ThreadsInterface;
use Switon\Agency\Tool\FormAskTool;
use Switon\Core\InjectorInterface;
use Switon\Core\PathAliasInterface;
use Switon\Core\ServerUuidInterface;
use Switon\Core\Strings;
use Switon\Tooling\ServiceProvider as ToolingServiceProvider;
use Switon\Ai\Completion;
use Switon\Ai\Thread;
use Switon\Ai\LlmInterface;
use Switon\Llm\LlmProvidersInterface;
use Switon\Ai\Message;
use Switon\Ai\Model;
use Switon\Testing\TestCase as BaseTestCase;
use ArrayIterator;
use LogicException;

use function is_array;
use function is_string;

/**
 * Agent DI + filesystem helpers without the Swoole scheduler wrapper.
 *
 * Use for pure unit tests (prompt rendering, thread storage, signals).
 * Async {@see Agent::deliver()} / inbox workers → {@see TestCase}.
 */
abstract class AgentContainerTestCase extends BaseTestCase
{
    /** Test-side extension registry; mutation goes through `set()` on this pinned instance. */
    protected MutableExtensionRegistry $extensionRegistry;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();
        $this->container->set(RecordingAgentManager::class, ['class' => RecordingAgentManager::class]);
        $this->container->set(LlmInterface::class, new StubLlm());
        // Resolve through container so tests that only re-bind LlmInterface still stub providers.
        $this->container->replace(LlmProvidersInterface::class, new StubLlmProviders($this->container));
        $pathAlias = $this->container->get(PathAliasInterface::class);
        $pathAlias->set('@agency.test.agents', __DIR__ . '/Fixtures/agents');
        $this->useProfilePaths([
            '@agency/agents',
            '@agency.test.agents',
        ]);
        // Pin one registry instance for the whole test: the container freezes a service on first
        // `get()`, so tests mutate `set()` on this object instead of rebinding the interface.
        $this->container->set(ExtensionsInterface::class, new MutableExtensionRegistry());
        $this->extensionRegistry = $this->container->get(ExtensionsInterface::class);
        // Agent is multi-instance (make per session) — bind concrete class only, never AgentInterface.
        $this->container->set(Agent::class, ['class' => UnitTestAgent::class]);
        $this->container->replace(FormAskTool::class, [
            'class' => FormAskTool::class,
            'types' => [
                'input',
                'text',
                'textarea',
                'number',
                'email',
                'tel',
                'url',
                'password',
                'select',
                'radio',
                'checkbox',
                'switch',
                'choice',
                'slider',
                'rate',
                'tags',
                'date',
                'datetime',
                'time',
                'month',
                'range',
                'date-range',
                'time-range',
                'datetime-range',
                'month-range',
                'color',
            ],
        ]);

        // Fold the llm toolkit into the definition first: `boot()` resolves {@see ToolsInterface}
        // through tool discovery, and `set()` cannot touch a resolved service.
        $this->ensureLlmToolkitDefinition();

        // Testing Container only register()s providers — boot tooling for package tools.
        $tooling = new ToolingServiceProvider();
        $this->container->get(InjectorInterface::class)->inject($tooling);
        $tooling->boot();

        $this->container->get(PromptVariableDiscoveryInterface::class)->discover();
    }

    /**
     * Fold `llm` into the ToolsInterface definition (no resolve; safe before child set()).
     */
    protected function ensureLlmToolkitDefinition(): void
    {
        $id = ToolsInterface::class;
        $def = $this->container->getDefinition($id);
        if (is_string($def)) {
            $def = ['class' => $def];
        } elseif (!is_array($def)) {
            $def = [];
        }
        $def['toolkits'] = [
            ...$this->builtinToolkitConfig(),
            ...($def['toolkits'] ?? []),
        ];
        $this->container->set($id, $def);
    }

    /**
     * Agent SP boot mounts this; tests that re-bind Tools must restate it.
     *
     * @return array<string, class-string>
     */
    protected function builtinToolkitConfig(): array
    {
        return [
            'agent' => AsToolToolkit::class,
        ];
    }

    protected function threadStore(): ThreadsInterface
    {
        return $this->container->get(ThreadsInterface::class);
    }

    /** @return array<string, mixed> */
    protected function agentState(AgentInterface $agent): array
    {
        return $agent->thread()->state;
    }

    protected function persistAgentState(AgentInterface $agent): void
    {
        $this->threadStore()->saveState($agent->thread());
    }

    /** Test storage path under `@threads` (host id or `{host}.{leaf}`). */
    protected function threadDir(string $threadId): string
    {
        if (!str_contains($threadId, '.')) {
            return '@threads/' . $threadId;
        }

        [$host, $leaf] = explode('.', $threadId, 2);

        return '@threads/' . $host . '/subagents/' . $leaf;
    }

    protected function threadCreate(string $id): Thread
    {
        $thread = Thread::of($id, $this->threadDir($id));
        $this->threadStore()->create($thread);

        return $thread;
    }

    protected function threadLoad(string $id): Thread
    {
        $thread = Thread::of($id, $this->threadDir($id));
        $this->threadStore()->load($thread);

        return $thread;
    }

    /** Load a thread leaf by datadir; the caller holds the returned instance. */
    protected function loadThread(string $datadir, ?string $id = null): Thread
    {
        $thread = Thread::of($id ?? basename($datadir), $datadir);
        $this->threadStore()->load($thread);

        return $thread;
    }

    /** @return array<string, mixed> */
    protected function threadLoadState(string $id): array
    {
        return $this->threadLoad($id)->state;
    }

    /** @param array<string, mixed> $state */
    protected function threadSaveState(string $id, array $state): void
    {
        $this->threadStore()->saveState(new Thread($id, state: $state, datadir: $this->threadDir($id)));
    }

    /** @internalTests for {@see Threads} helpers. */
    protected function threadsImpl(): Threads
    {
        $threads = $this->threadStore();
        if (!$threads instanceof Threads) {
            throw new LogicException('ThreadsInterface must resolve to Threads in tests.');
        }

        return $threads;
    }

    /** Point thread file layout at a temp root via `@threads`. */
    protected function bindThreadStorageRoot(string $root): void
    {
        $manager = $this->container->has(RecordingAgentManager::class)
            ? $this->container->get(RecordingAgentManager::class)
            : null;
        if ($manager instanceof RecordingAgentManager) {
            $manager->stopTrackedAgents();
        }

        foreach ([
            ThreadsInterface::class,
            Threads::class,
            RecordingAgentManager::class,
        ] as $id) {
            if ($this->container->has($id)) {
                $this->container->remove($id);
            }
        }

        $this->container->get(PathAliasInterface::class)->set('@threads', $root);

        $this->container->set(RecordingAgentManager::class, ['class' => RecordingAgentManager::class]);
        $this->container->get(ServerUuidInterface::class)->get();
    }

    protected function threadLeafDir(string $threadId): string
    {
        return $this->container->get(PathAliasInterface::class)->resolve($this->threadDir($threadId));
    }

    /**
     * @param list<string> $tools
     * @param list<string> $skills
     */
    protected function testProfile(
        string $id = 'test',
        string $prompt = '',
        array $tools = [],
        array $skills = [],
        string $model = 'main',
        int $maxSteps = 10,
    ): Profile {
        return Profile::decode([
            'id' => $id,
            'prompt' => $prompt,
            'tools' => $tools,
            'skills' => $skills,
            'model' => $model,
            'max_steps' => $maxSteps,
        ]);
    }

    protected function deliveryStartedEvent(Agent $agent, Message $message, string $runId = '', string $turnId = ''): DeliveryStarted
    {
        $runId = $runId !== '' ? $runId : Strings::uuid();
        $turnId = $turnId !== '' ? $turnId : $runId;

        $agent->thread()->turns[] = \Switon\Ai\Turn::of(
            $turnId,
            status: TurnStatus::Running,
            model: $agent->profile()->model,
        );

        $completion = Completion::of($message);
        $completion->completeId = $runId;
        $completion->step = 1;

        return new DeliveryStarted($agent, $completion);
    }

    protected function setTestProfile(Profile $config): void
    {
        $this->container->get(ProfilesInterface::class)->set($config);
    }

    protected function promptFromResource(string $alias): string
    {
        $path = $this->container->get(PathAliasInterface::class)->resolve($alias);

        return trim((string) file_get_contents($path));
    }

    /**
     * In-memory agent for {@see ChartersInterface::get()}. Does not start the inbox.
     *
     * @param list<mixed> $segments
     */
    protected function memoryAgent(
        string $profileId,
        array $segments = [],
        bool $rootAgent = true,
        string $workspace = '',
    ): Agent {
        $profile = $this->container->get(ProfilesInterface::class)->get($profileId);
        if ($rootAgent && $segments !== []) {
            $profile = clone $profile;
            $profile->segments = [...$profile->segments, ...$segments];
        }
        $parameters = [
            'thread' => Thread::of($profile->id),
            'profile' => $profile,
            'workspace' => $workspace,
        ];
        if (!$rootAgent) {
            $parameters['parent'] = $this->container->make(Agent::class, [
                'thread' => Thread::of($profile->id . '.root'),
                'profile' => $profile,
                'workspace' => $workspace,
            ]);
        }

        return $this->container->make(Agent::class, $parameters);
    }

    protected function segmentPrompt(string $id): string
    {
        return $this->container->get(SegmentsInterface::class)->get($id)->prompt;
    }

    protected function profilePrompt(string $id): string
    {
        return $this->container->get(ProfilesInterface::class)->get($id)->prompt;
    }

    /**
     * @param list<Completion> $completions
     *
     * @return list<Message>
     */
    protected function flattenWireMessages(array $completions, ?Completion $omit = null): array
    {
        $messages = [];
        foreach ($completions as $completion) {
            if ($omit !== null && $completion === $omit) {
                continue;
            }
            $messages[] = $completion->input;
            if ($completion->output !== null) {
                $messages[] = $completion->output;
            }
        }

        return $messages;
    }

    /**
     * Rebind profile discovery to these roots and load a fresh catalog.
     *
     * @param list<string> $paths
     */
    protected function useProfilePaths(array $paths): void
    {
        $this->container->replace(ProfileDiscoveryInterface::class, [
            'class' => ProfileDiscovery::class,
            'paths' => new ArrayIterator($paths),
        ]);
        $this->container->remove(ProfilesInterface::class);
        $this->container->remove(SegmentsInterface::class);
        $this->container->get(ProfileDiscoveryInterface::class)->discover();
    }
}
