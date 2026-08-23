<?php

declare(strict_types=1);

use Switon\Ai\Signal;
use Switon\Thread\Threads;
use Switon\Thread\ThreadsInterface;

if (!defined('SWITON_AGENT_LIVE_BOOTSTRAP_ROOT')) {
    define('SWITON_AGENT_LIVE_BOOTSTRAP_ROOT', dirname(__DIR__, 4));
}

// Idempotent: PHPUnit may already have loaded tests/bootstrap.php via --bootstrap.
// Split-repo unit tests load this file without a monorepo root above the package.
if (!function_exists('switon_register_psr4_autoload')) {
    $autoload = SWITON_AGENT_LIVE_BOOTSTRAP_ROOT . '/vendor/autoload.php';
    $bootstrap = SWITON_AGENT_LIVE_BOOTSTRAP_ROOT . '/tests/bootstrap.php';
    if (is_file($autoload) && is_file($bootstrap)) {
        require $autoload;
        require $bootstrap;
    }
}

if (!defined('SWITON_AGENT_LIVE_TEST_AUTOLOAD') && function_exists('switon_register_monorepo_test_autoloads')) {
    define('SWITON_AGENT_LIVE_TEST_AUTOLOAD', true);
    switon_register_monorepo_test_autoloads(
        SWITON_AGENT_LIVE_BOOTSTRAP_ROOT . '/packages/agency',
        SWITON_AGENT_LIVE_BOOTSTRAP_ROOT,
    );
}

if (!function_exists('liveEnableCoroutineRuntime')) {
    /** Mirror {@see \Switon\Kernel\Kernel::detectCoroutineCanEnabled()} for manual live scripts. */
    function liveEnableCoroutineRuntime(): void
    {
        if (PHP_SAPI !== 'cli' || !extension_loaded('swoole')) {
            return;
        }

        if (extension_loaded('xdebug') && function_exists('xdebug_info')) {
            $modes = xdebug_info('mode');
            if (is_array($modes) && $modes !== []) {
                return;
            }
        }

        // Hook blocking IO (curl, file, sleep, …) so parent/child agents can interleave in coroutines.
        \Switon\Core\Runtime::setCoroutineEnabled(true);
        if (defined('SWOOLE_HOOK_ALL')) {
            \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        } else {
            \Swoole\Runtime::enableCoroutine(true);
        }
    }
}

if (function_exists('liveEnableCoroutineRuntime') && !defined('SWITON_AGENT_LIVE_BOOTSTRAP_READY')) {
    define('SWITON_AGENT_LIVE_BOOTSTRAP_READY', true);
    $skipAutoEnable = defined('PHPUNIT_COMPOSER_INSTALL')
        || (static function (): bool {
            foreach ($_SERVER['argv'] ?? [] as $arg) {
                if (is_string($arg) && str_contains($arg, 'phpunit')) {
                    return true;
                }
            }

            return false;
        })();
    if (!$skipAutoEnable) {
        liveEnableCoroutineRuntime();
    }
}

/**
 * Shared bootstrap for manual live scripts under packages/agency/tests/manual/.
 *
 * Usage:
 *
 *     require __DIR__ . '/live_bootstrap.php';
 *     Runner::run(function (ContainerInterface $container): void {
 *         liveBindTools($container, ['bash']); // omit to keep the default tools
 *         liveRegisterAgentPackage($container);      // RecordingAgentManager + drain counter
 *         liveStartConsole($container);              // opt-in transcript
 *     });
 */

use Switon\Agency\Agent;
use Switon\Agency\Observability\AgentConsoleInterface;
use Switon\Agency\Tests\Fixtures\DelegatedAgentWithCreate;
use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Agency\Tool\FormAskTool;
use Switon\Agency\ProfileDiscoveryInterface;
use Switon\Agency\ProfilesInterface;
use Switon\Ai\Envelope;
use Switon\Ai\Event\DeliverySettled;
use Switon\Ai\Event\DeliveryStarted;
use Switon\Ai\LlmInterface;
use Switon\Ai\Profile;
use Switon\Ai\Thread;
use Switon\Cli\Console;
use Switon\Core\App;
use Switon\Core\ConsoleInterface;
use Switon\Core\InjectorInterface;
use Switon\Core\PathAliasInterface;
use Switon\Core\Runtime;
use Switon\Core\Strings;
use Switon\Di\ContainerInterface;
use Switon\Eventing\Attribute\EventListener;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Eventing\ObservabilityProbe;
use Switon\Llm\Anthropic;
use Switon\Llm\Llm;
use Switon\Llm\LlmDiscovery;
use Switon\Llm\LlmDiscoveryInterface;
use Switon\Llm\LlmProviders;
use Switon\Llm\LlmProvidersInterface;
use Switon\Llm\OpenAi;
use Switon\McpClient\ClientDiscovery;
use Switon\McpClient\ClientDiscoveryInterface;
use Switon\Search\Tool\WebFetchTool;
use Switon\Search\Tool\WebSearchTool;
use Switon\Search\Tool\SiteSearchTool;
use Switon\Skill\SkillsInterface;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;

function liveRepoRoot(): string
{
    return SWITON_AGENT_LIVE_BOOTSTRAP_ROOT;
}

/**
 * Repo root in POSIX form, for text a model or tool reads.
 *
 * `liveRepoRoot()` keeps the platform separator because callers `chdir()` into it; prompts append
 * `/packages/...`, which on Windows would mix `C:\repo` with forward slashes.
 */
function livePromptRoot(): string
{
    return strtr(SWITON_AGENT_LIVE_BOOTSTRAP_ROOT, '\\', '/');
}

function liveAgentPackageRoot(): string
{
    return dirname(__DIR__, 2);
}

/** @return list<\Switon\Ai\Turn> Sealed rows from `transcript.jsonl` (full history; not compacted conversation). */
function liveTranscriptJsonlTurns(\Switon\Core\FilesystemInterface $fs, string $datadir): array
{
    $turns = [];
    foreach ($fs->readJsonl(rtrim($datadir, '/') . '/transcript.jsonl') as $row) {
        $turns[] = \Switon\Ai\Turn::decode($row);
    }

    return $turns;
}

/**
 * Run live agent work inside Swoole coroutine scheduler when coroutines are on.
 *
 * {@see \Swoole\Coroutine\run()} already waits like CLI {@see \Swoole\Event::wait()}.
 * Agent inbox workers must be {@see liveShutdownOpenAgents()} before it returns.
 */
function liveRunInCoroutine(callable $fn): mixed
{
    // PHPUnit {@see \Switon\Agency\Tests\TestCase::invokeTestMethod()} already wraps in Coroutine::run.
    $alreadyInCoroutine = extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
    if (!Runtime::isCoroutineEnabled() || $alreadyInCoroutine) {
        try {
            return $fn();
        } catch (\Throwable $e) {
            if ($alreadyInCoroutine) {
                throw $e;
            }
            liveFail($e);
        } finally {
            liveShutdownOpenAgents();
        }
    }

    $result = null;
    $error = null;
    \Swoole\Coroutine\run(static function () use ($fn, &$result, &$error): void {
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $error = $e;
        } finally {
            liveShutdownOpenAgents();
        }
    });
    if ($error !== null) {
        liveFail($error);
    }

    return $result;
}

/** Print a live harness failure and exit (avoids uncaught Fatal with worker stack). */
function liveFail(\Throwable $error): never
{
    $line = $error->getMessage();
    if ($error instanceof \Switon\Llm\Exception\HttpStatusException) {
        $body = trim($error->getResponseBody());
        if ($body !== '') {
            $line .= ' body=' . substr($body, 0, 240);
        }
    }
    fwrite(STDERR, "\nLIVE ERROR: {$line}\n");
    fwrite(STDERR, 'RESULT: INCONCLUSIVE/FAIL (runtime: ' . $error::class . ")\n");
    exit(1);
}

/**
 * Close inboxes so worker coroutines exit (avoids Swoole "all coroutines asleep" on script end).
 */
function liveKillAgentTree(\Switon\Ai\AgentInterface $agent): void
{
    $agent->shutdown();
}

/** @return array<string, Agent> */
function liveAgentChildren(Agent $parent): array
{
    $ref = new \ReflectionProperty(Agent::class, 'subagents');

    return $ref->getValue($parent);
}

function liveAgentIsTaskOpen(Agent $agent): bool
{
    $parent = $agent->parent();
    if (!$parent instanceof Agent) {
        return false;
    }

    return isset(liveAgentChildren($parent)[$agent->threadId()]);
}

/** @var array<string, \Switon\Ai\Completion> */
$liveStagedTaskFrom = [];

function liveAgentOpenTask(Agent $agent, string $taskId): void
{
    $completion = \Switon\Ai\Completion::of(\Switon\Ai\Message\UserMessage::of('test'));
    $completion->completeId = $taskId;
    $liveStagedTaskFrom[$agent->threadId()] = $completion;
}

function liveModel(ContainerInterface $container, string $pick): string
{
    return $pick;
}

function liveAgentDeliver(ContainerInterface $container, Agent $agent, \Switon\Ai\Message $message): void
{
    global $liveStagedTaskFrom;

    $from = $liveStagedTaskFrom[$agent->threadId()] ?? null;
    unset($liveStagedTaskFrom[$agent->threadId()]);
    // openTask stores a Completion as staging; only Turn is a valid from for deliverTask workers.
    $turn = $from instanceof \Switon\Ai\Turn ? $from : null;
    $agent->deliver(Envelope::of($message, liveModel($container, $agent->profile()->model), from: $turn));
}

function liveAgentRegisterChild(Agent $parent, Agent $child): void
{
    $children = liveAgentChildren($parent);
    $children[$child->threadId()] = $child;
    (new \ReflectionProperty($parent, 'subagents'))->setValue($parent, $children);
}

/** @return list<\Switon\Ai\AgentInterface> */
function &liveOpenAgents(): array
{
    static $agents = [];

    return $agents;
}

function liveTrackOpenAgent(\Switon\Ai\AgentInterface $agent): void
{
    static $shutdownRegistered = false;
    if (!$shutdownRegistered) {
        $shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            liveShutdownOpenAgents();
        });
    }
    foreach (liveOpenAgents() as $tracked) {
        if ($tracked === $agent) {
            return;
        }
    }
    liveOpenAgents()[] = $agent;
}

/**
 * Create a session agent and register it for {@see liveShutdownOpenAgents()} on script/coroutine exit.
 */
function liveOpenAgent(
    ContainerInterface $container,
    string $agentClass,
    array $params = [],
): \Switon\Ai\AgentInterface {
    liveRegisterAgentPackage($container);
    liveStartConsole($container);
    $agent = $container->get(RecordingAgentManager::class)->mint($params, $agentClass);
    liveTrackOpenAgent($agent);

    return $agent;
}

/** Same-id test/live override ({@see Agent::switchProfile()} changes registry ids). */
function liveBindProfileOverride(Agent $agent, Profile $config, ContainerInterface $container): void
{
    (new \ReflectionProperty(Agent::class, 'profile'))->setValue($agent, $config);
}

function liveShutdownOpenAgents(): void
{
    $container = App::getContainer();
    if ($container !== null) {
        try {
            $manager = $container->get(RecordingAgentManager::class);
            if ($manager instanceof RecordingAgentManager) {
                $manager->stopTrackedAgents();
            }
        } catch (\Throwable) {
            // Best-effort teardown for manual scripts.
        }
    }

    $agents = &liveOpenAgents();
    foreach (array_reverse($agents) as $agent) {
        try {
            liveKillAgentTree($agent);
        } catch (\Throwable) {
            // Best-effort teardown for manual scripts.
        }
    }
    $agents = [];
}

/**
 * Wait until the agent tree is quiet (no new conversation messages, no tasking subs)
 * or {@see $timeoutSeconds} elapses.
 * Prefers {@see \Switon\Ai\InstructorInterface::instruct()} / {@see \Switon\Ai\AgentInterface::run()} when one sync result is enough.
 * Does not start the quiet clock until the tree has shown activity (message growth or tasking),
 * so `deliver()` + drain is not killed during the first in-flight LLM.
 */
function liveDrainDelegations(
    \Switon\Agency\Agent $agent,
    float $timeoutSeconds = 120.0,
    float $quietSeconds = 4.0,
): void {
    liveTrackOpenAgent($agent);

    if (!\Switon\Core\Runtime::isCoroutineEnabled()) {
        return;
    }

    $deadline = microtime(true) + max(0.1, $timeoutSeconds);
    $lastChange = microtime(true);
    $lastCount = liveAgentTreeMessageCount($agent);
    $seenActivity = $lastCount > 0 || liveAgentTreeTasking($agent);

    while (microtime(true) < $deadline) {
        \Swoole\Coroutine::sleep(0.2);
        $count = liveAgentTreeMessageCount($agent);
        if ($count !== $lastCount) {
            $seenActivity = true;
            $lastCount = $count;
            $lastChange = microtime(true);
            continue;
        }
        // Blocked LLM calls add no messages until they return; keep waiting while any sub is tasking.
        if (liveAgentTreeTasking($agent)) {
            $seenActivity = true;
            $lastChange = microtime(true);
            continue;
        }
        // Finish/ask may sit in an inbox until the parent's worker pops it — not a domain "busy" flag,
        // just "queue not empty yet" so drain does not kill before the next agent run starts.
        if (liveAgentTreeInboxPending($agent)) {
            $seenActivity = true;
            $lastChange = microtime(true);
            continue;
        }
        // Host/sub still inside run() (e.g. step 1 landed, step 2 LLM in flight).
        if (liveAgentTreeRunActive($agent)) {
            $seenActivity = true;
            $lastChange = microtime(true);
            continue;
        }
        if (!$seenActivity) {
            continue;
        }
        if ((microtime(true) - $lastChange) >= $quietSeconds) {
            return;
        }
    }
}

function liveAgentTreeInboxPending(\Switon\Agency\Agent $agent): bool
{
    $ref = new \ReflectionProperty(\Switon\Agency\Agent::class, 'inbox');
    $inbox = $ref->getValue($agent);
    if ($inbox instanceof \Swoole\Coroutine\Channel && $inbox->length() > 0) {
        return true;
    }
    foreach (liveAgentChildren($agent) as $child) {
        if (liveAgentTreeInboxPending($child)) {
            return true;
        }
    }

    return false;
}

function liveAgentTreeTasking(\Switon\Agency\Agent $agent): bool
{
    foreach (liveAgentChildren($agent) as $child) {
        if (liveAgentIsTaskOpen($child)) {
            return true;
        }
        if (liveAgentTreeTasking($child)) {
            return true;
        }
    }

    return false;
}

function liveAgentTreeRunActive(Agent $agent): bool
{
    if (LiveRunTracker::countFor($agent->threadId()) > 0) {
        return true;
    }
    foreach (liveAgentChildren($agent) as $child) {
        if (liveAgentTreeRunActive($child)) {
            return true;
        }
    }

    return false;
}

function liveAgentTreeMessageCount(\Switon\Agency\Agent $agent): int
{
    $ref = new \ReflectionProperty(\Switon\Agency\Agent::class, 'thread');
    /** @var \Switon\Ai\Thread $thread */
    $thread = $ref->getValue($agent);
    $n = count(liveWireMessages($thread->completions()));
    foreach (liveAgentChildren($agent) as $child) {
        $n += liveAgentTreeMessageCount($child);
    }

    return $n;
}

/** @return array<string, mixed>|null */
function liveTaskEventFromMessage(\Switon\Ai\Message $message): ?array
{
    if (!$message instanceof \Switon\Agency\Thread\TaskFinishSignal) {
        return null;
    }

    return $message->fields + [
        'signal' => $message->name,
        'body' => $message->body,
    ];
}

/** @return array{baseUrl: string, model: string} */
function liveLlmSummary(ContainerInterface $container): array
{
    $llm = $container->get(LlmInterface::class);
    $ref = new ReflectionClass($llm);

    return [
        'baseUrl' => $ref->hasProperty('baseUrl') ? (string) $ref->getProperty('baseUrl')->getValue($llm) : '',
        'model' => $llm->model()->model,
    ];
}

function liveEchoLlmSummary(ContainerInterface $container): void
{
    $config = liveLlmSummary($container);
    $hooks = extension_loaded('swoole') && defined('SWOOLE_HOOK_ALL')
        ? 'all'
        : (extension_loaded('swoole') ? 'default' : 'off');
    echo "Model: {$config['model']}\n";
    echo "Base:  {$config['baseUrl']}\n";
    echo 'Swoole hooks: ' . $hooks . "\n\n";
}

function liveBindLlm(ContainerInterface $container): void
{
    // Test setup pins a stub provider that resolves LlmInterface from the container.
    // The real factory calls that provider, so the stub has to go first.
    // PHPUnit registers providers without booting them, so load the catalog here.
    $container->replace(LlmProvidersInterface::class, LlmProviders::class);
    $container->replace(LlmDiscoveryInterface::class, LlmDiscovery::class);
    $container->get(LlmDiscoveryInterface::class)->discover();
    liveRebind($container, LlmInterface::class, Llm::class);
}

/**
 * @param 'openai'|'anthropic' $provider
 *
 * @return array{model: string, baseUrl: string, provider: string}
 */
function liveBindNamedLlm(ContainerInterface $container, string $provider, ?float $temperature = null): array
{
    if (!in_array($provider, ['openai', 'anthropic'], true)) {
        throw new InvalidArgumentException('provider must be "openai" or "anthropic".');
    }

    $providers = $container->get(LlmProvidersInterface::class);

    $model = $provider . '/default';
    if (!$providers->has($model)) {
        foreach ($providers->all() as $name => $llm) {
            if ($name === $llm->model()->id && str_starts_with($name, $provider . '/')) {
                $model = $name;
                break;
            }
        }
    }
    if (!$providers->has($model)) {
        throw new RuntimeException(
            "No \"{$provider}/*\" model in ~/.switon/llm.yml (see packages/llm/resources/llm.yml.example).",
        );
    }

    $llm = $providers->get($model);
    unset($temperature); // catalog temperature wins; keep signature for callers
    liveRebind($container, LlmInterface::class, $llm);

    $ref = new ReflectionClass($llm);

    return [
        'model' => $llm->model()->model,
        'baseUrl' => $ref->hasProperty('baseUrl') ? (string) $ref->getProperty('baseUrl')->getValue($llm) : '',
        'provider' => $provider,
    ];
}

/**
 * @return array<string, class-string>
 */
function liveBuiltinToolMap(ContainerInterface $container): array
{
    /** @var array<string, class-string> $defaults */
    $defaults = (new \ReflectionProperty(\Switon\Tooling\Tools::class, 'tools'))->getDefaultValue();
    $package = iterator_to_array(
        $container->get(\Switon\ComposerExtra\ComposerExtraInterface::class)->collect('switon.tools'),
    );
    // Optional domain tools: apps wire via switon.yml; manuals may bind by name.
    return $defaults + $package + [
        'web_fetch' => WebFetchTool::class,
        'web_search' => WebSearchTool::class,
        'site_search' => SiteSearchTool::class,
    ];
}

/**
 * @param iterable<string, class-string>|list<string>|'*' $tools
 */
function liveBindTools(ContainerInterface $container, iterable|string $tools = '*'): void
{
    if ($tools === '*') {
        return;
    }

    if (is_array($tools) && $tools !== [] && array_is_list($tools)) {
        $tools = array_intersect_key(liveBuiltinToolMap($container), array_flip($tools));
    }
    if ($tools instanceof \Traversable) {
        $tools = iterator_to_array($tools);
    }

    if (liveIsResolved($container, ToolsInterface::class)) {
        $registered = [];
        foreach ($tools as $name => $spec) {
            $registered[$name] = liveMakeTool($container, $spec);
        }
        (new ReflectionProperty(Tools::class, 'tools'))->setValue(
            $container->get(ToolsInterface::class),
            $registered,
        );

        return;
    }

    $container->replace(ToolsInterface::class, [
        'class' => Tools::class,
        'tools' => $tools,
    ]);
}

function liveMakeTool(ContainerInterface $container, mixed $spec): object
{
    if (is_object($spec)) {
        return $spec;
    }
    if (is_string($spec)) {
        return $container->get($spec);
    }
    if (is_array($spec) && isset($spec['class']) && is_string($spec['class'])) {
        return $container->make($spec['class'], $spec);
    }

    throw new InvalidArgumentException('Tool spec must be an object, class name, or array with class.');
}

/** set() before the first get(); replace() after. Holders of the old instance are unchanged. */
function liveRebind(ContainerInterface $container, string $id, mixed $definition): void
{
    if (liveIsResolved($container, $id)) {
        $container->replace($id, $definition);

        return;
    }

    $container->set($id, $definition);
}

function liveIsResolved(ContainerInterface $container, string $id): bool
{
    return method_exists($container, 'isResolved') && $container->isResolved($id);
}

/**
 * Drop the MCP catalog already loaded at kernel boot.
 *
 * StdioTransport::close() uses usleep and fatals when a live client is destroyed after the scheduler stops.
 * ClientDiscovery::discover() has already run, so replacing the container binding leaves the live clients in place.
 */
function liveDisableMcp(ContainerInterface $container): void
{
    $file = sys_get_temp_dir() . '/switon-live-no-mcp.yml';
    if (is_file($file)) {
        unlink($file);
    }

    if (!liveIsResolved($container, ClientDiscoveryInterface::class)) {
        $container->set(ClientDiscoveryInterface::class, [
            'class' => ClientDiscovery::class,
            'file' => $file,
        ]);

        return;
    }

    $discovery = $container->get(ClientDiscoveryInterface::class);
    (new ReflectionProperty(ClientDiscovery::class, 'file'))->setValue($discovery, $file);
    $discovery->discover();
}

/** Opt-in transcript. Once per container. */
function liveStartConsole(ContainerInterface $container): void
{
    static $started = [];
    $id = spl_object_id($container);
    if (isset($started[$id])) {
        return;
    }
    $started[$id] = true;
    $container->get(AgentConsoleInterface::class)->start();
    liveEchoLlmSummary($container);
}

/** Timer leaf target depth N → first relay gets remaining_depth N−2 (timer via agent_* from depth N−1). */
function liveNestingFirstRemainingDepth(int $timerAtDepth): int
{
    return max(0, $timerAtDepth - 2);
}

/** Standard delegate body — relay forwards verbatim, only decrements remaining_depth. */
function liveNestingDelegateMessage(int $timerAtDepth): string
{
    $remaining = liveNestingFirstRemainingDepth($timerAtDepth);

    return "Goal: current UTC time, HH:MM:SS only. remaining_depth: {$remaining}";
}

/** @return 'sync'|'async-host'|'async-full' */
function liveNestingWaitMode(array $argv): string
{
    foreach (array_slice($argv, 1) as $arg) {
        if (in_array($arg, ['async-full', 'async:full', 'async-all', '--async-full'], true)) {
            return 'async-full';
        }
        if ($arg === 'async' || $arg === '--async') {
            return 'async-host';
        }
    }

    return 'sync';
}

/** Extra orchestrator prompt for AgentConsole nesting manual scripts only. */
function liveNestingDepthPromptAddendum(string $waitMode = 'sync'): string
{
    $waitLine = match ($waitMode) {
        'async-host' => '- Create **one** sub-agent, then `task_deliver` **once** with the delegate message from the user (copy goal + remaining_depth verbatim). `task_deliver` ends your run; the relay result arrives later via inbox `task_finish`.',
        'async-full' => '- `task_deliver` **once** with the delegate message (copy goal + remaining_depth verbatim). Intermediate relays and the timer leaf use mounted `agent_*` tools.',
        default => '- Create **one** sub-agent, then `task_deliver` **once** with the delegate message from the user (copy goal + remaining_depth verbatim).',
    };

    return <<<TEXT


## Nesting demo (this manual script only)

- You are depth 0. Do not spawn multiple depth-labeled siblings (depth2, relay6, …).
{$waitLine}
- Deeper levels are spawned by the **current** sub-agent via mounted `agent_*` tools, not parallel assigns from you.
- `agent` selects the child profile.
TEXT;
}

/** Parse optional timer-at-depth integer from manual script argv (ignores wait-mode flags). */
function liveNestingDepthFromArgv(array $argv, int $default): int
{
    $skip = ['async', '--async', 'async-full', 'async:full', 'async-all', '--async-full'];

    foreach (array_slice($argv, 1) as $arg) {
        if (in_array($arg, $skip, true)) {
            continue;
        }
        if (is_numeric($arg)) {
            return max(3, (int) $arg);
        }
    }

    return $default;
}

/** Host user message for nesting manual scripts. */
function liveNestingHostUserPrompt(string $delegateMessage, string $waitMode, string $profileId): string
{
    if ($waitMode === 'async-host') {
        return <<<TXT
We're testing AgentConsole nesting with host `task_deliver` inbox delegation. You're the host (depth 0).

Call task_deliver once with agent={$profileId} and message= the following text verbatim:

{$delegateMessage}

Use real tools — do not simulate. Report the UTC time (HH:MM:SS only) when task_finish arrives in inbox.
TXT;
    }

    if ($waitMode === 'async-full') {
        return <<<TXT
We're testing AgentConsole nesting with async host dispatch and synchronous nested `agent_*` tools. You're the host (depth 0).

Call task_deliver once with agent={$profileId} and message= the following text verbatim:

{$delegateMessage}

Use real tools — do not simulate. Report the UTC time (HH:MM:SS only) when task_finish arrives in inbox.
TXT;
    }

    return <<<TXT
We're testing AgentConsole nesting. You're the host (depth 0).

Call task_deliver once with agent={$profileId} and message= the following text verbatim:

{$delegateMessage}
TXT;
}

function liveNestingWaitModeLabel(string $waitMode): string
{
    return match ($waitMode) {
        'async-host' => 'async-host (host task_deliver inbox)',
        'async-full' => 'async-full (async host + synchronous nested runs)',
        default => 'sync (sequential inbox)',
    };
}

/** Describe the legacy async-full flag under synchronous nested execution. */
function liveNestingAsyncFullWarning(string $waitMode): void
{
    if ($waitMode !== 'async-full') {
        return;
    }

    $coroutines = Runtime::isCoroutineEnabled() ? 'on' : 'off';
    $swoole = extension_loaded('swoole') ? 'loaded' : 'missing';
    echo "Coroutine runtime: {$coroutines} (swoole: {$swoole})\n";
    echo "Note: async-full now uses task_deliver only at the host and agent_* below it.\n\n";
}

/**
 * Orchestrator with built-in profiles (@agency/agents; app-coding adds @resources/agents).
 *
 * Start params are stored under {@see liveOrchestratorStartParams()} — not on the Orchestrator DI definition.
 */
function liveConfigureOrchestratorContainer(
    ContainerInterface $container,
    ?string $repoRoot = null,
    int $loopLimit = 25,
): void {
    $repoRoot ??= liveRepoRoot();
    chdir($repoRoot);

    $container->set(liveOrchestratorStartParamsId(), [
        'profileId' => 'host',
        'maxSteps' => $loopLimit,
    ]);
}

/** chdir to the repo. Coding profiles and tools are already on the kernel container. */
function liveConfigureCodingContainer(?string $repoRoot = null): void
{
    $repoRoot ??= liveRepoRoot();
    chdir($repoRoot);
}

/**
 * Spawn a coding lead, deliver $task, drain delegations, print session paths.
 *
 * @return string thread id
 */
function liveRunCodingPipeline(
    ContainerInterface $container,
    string $task,
    float $drainTimeoutSeconds = 120.0,
    float $quietSeconds = 4.0,
    ?string $bannerExtra = null,
): string {
    $repoRoot = liveRepoRoot();
    liveRegisterAgentPackage($container);
    liveStartConsole($container);
    $lead = $container->get(RecordingAgentManager::class)->mint([]);
    $threadId = $lead->threadId();

    echo "Repo: {$repoRoot}\n";
    echo "thread={$threadId}\n";
    if ($bannerExtra !== null && $bannerExtra !== '') {
        echo rtrim($bannerExtra, "\n") . "\n";
    }
    echo "\n";

    try {
        liveRunInCoroutine(static function () use ($lead, $task, $drainTimeoutSeconds, $quietSeconds): void {
            $lead->deliver(Envelope::of($task, liveModel($container, $lead->profile()->model)));
            liveDrainDelegations($lead, $drainTimeoutSeconds, $quietSeconds);
        });
    } catch (\Switon\Llm\Exception\HttpStatusException $e) {
        fwrite(STDERR, "LLM HTTP {$e->getStatusCode()}: {$e->getResponseBody()}\n");
        exit(1);
    } catch (\Switon\Agency\Exception $e) {
        fwrite(STDERR, "Agent error: {$e->getMessage()}\n");
        exit(1);
    }

    $runtime = $container->get(PathAliasInterface::class)->get('@runtime');
    echo "Session dir: {$runtime}/threads/{$threadId}\n";
    echo "  conversation.jsonl\n  history.md\n\nOK\n";

    return $threadId;
}

/** Bag id for {@see liveOpenOrchestrator()} start params (not an agent class). */
function liveOrchestratorStartParamsId(): string
{
    return 'live.orchestrator.start';
}

/**
 * @return array<string, mixed>
 */
function liveOrchestratorStartParams(ContainerInterface $container): array
{
    $def = $container->getDefinition(liveOrchestratorStartParamsId());

    return is_array($def) ? $def : [];
}


/** Register nesting-demo profiles under tests/manual/agents (later path overrides built-ins). */
function liveApplyNestingProfileBindings(ContainerInterface $container): void
{
    $container->get(PathAliasInterface::class)->set('@agency.live.profiles', __DIR__ . '/agents');
    liveAppendProfilePaths($container, ['@agency.live.profiles']);
    liveRebind($container, Agent::class, [
        'class' => DelegatedAgentWithCreate::class,
    ]);
}

/** Open Orchestrator: omit $threadId to spawn; pass only to resume an existing thread. */
function liveOpenOrchestrator(ContainerInterface $container, ?string $threadId = null): Agent
{
    liveRegisterAgentPackage($container);
    liveStartConsole($container);
    $manager = $container->get(RecordingAgentManager::class);
    if ($threadId !== null && $threadId !== '') {
        $datadir = '@threads/' . $threadId;
        $thread = Thread::of($threadId, $datadir);
        $container->get(ThreadsInterface::class)->load($thread);
        /** @var Agent $agent */
        $agent = $container->make(Agent::class, [
            'thread' => $thread,
            'workspace' => (string) ($thread->state['workspace'] ?? ''),
            'profile' => $container->get(ProfilesInterface::class)->get($thread->state['profileId']),
        ]);
        $agent->start();
        liveTrackOpenAgent($agent);

        return $agent;
    }

    $params = liveOrchestratorStartParams($container);
    $maxSteps = (int) ($params['maxSteps'] ?? 0);
    unset($params['maxSteps']);

    /** @var Agent $agent */
    $agent = $manager->mint($params);
    if ($maxSteps > 0) {
        $profile = clone $agent->profile();
        $profile->maxSteps = $maxSteps;
        liveBindProfileOverride($agent, $profile, $container);
    }
    liveTrackOpenAgent($agent);

    return $agent;
}

/** Fixture mint plus the drain counter. Kernel boot already registered the agency package. */
function liveRegisterAgentPackage(ContainerInterface $container): void
{
    if (!$container->has(RecordingAgentManager::class)) {
        $container->set(RecordingAgentManager::class, RecordingAgentManager::class);
    }

    LiveRunTracker::arm($container->get(ListenerProviderInterface::class));
}

/** @internal Manual live harness: track DeliveryStarted/Finished so drain waits for in-flight runs. */
class LiveRunTracker implements ObservabilityProbe
{
    /** @var array<int, true> */
    protected static array $armed = [];

    /** @var array<string, int> */
    protected static array $active = [];

    public static function arm(ListenerProviderInterface $provider): void
    {
        $id = spl_object_id($provider);
        if (isset(self::$armed[$id])) {
            return;
        }
        self::$armed[$id] = true;
        $provider->register(new self());
    }

    public static function countFor(string $threadId): int
    {
        return self::$active[$threadId] ?? 0;
    }

    #[EventListener]
    public function onDeliveryStarted(DeliveryStarted $event): void
    {
        $id = $event->sender->threadId();
        self::$active[$id] = (self::$active[$id] ?? 0) + 1;
    }

    #[EventListener]
    public function onDeliverySettled(DeliverySettled $event): void
    {
        $id = $event->sender->threadId();
        if (!isset(self::$active[$id])) {
            return;
        }

        self::$active[$id]--;
        if (self::$active[$id] <= 0) {
            unset(self::$active[$id]);
        }
    }
}

/** `label` is assistant `x` or tool `x,y` (digit string). */
function liveIsTrimLabel(mixed $label): bool
{
    if (is_int($label)) {
        return $label >= 0;
    }

    return is_string($label) && preg_match('/^\d+(?:,\d+)?$/', $label) === 1;
}

/**
 * @param array<string, mixed> $arguments
 *
 * @return list<array{label: int|string, message: string}>
 */
function liveFilterTrimItems(array $arguments): array
{
    $filtered = [];
    foreach ($arguments['items'] ?? [] as $item) {
        if (!is_array($item) || !liveIsTrimLabel($item['label'] ?? null)) {
            continue;
        }

        if (!isset($item['message']) || !is_string($item['message'])) {
            continue;
        }

        $message = $item['message'];

        $filtered[] = ['label' => $item['label'], 'message' => $message];
    }

    return $filtered;
}

/**
 * Pair flat fixture messages into Completions (test helper — not Conversation API).
 *
 * @param list<\Switon\Ai\Message> $messages
 *
 * @return list<\Switon\Ai\Completion>
 */
function liveCompletionsFromMessages(array $messages): array
{
    $completions = [];
    $pending = null;
    foreach ($messages as $message) {
        if ($message instanceof \Switon\Ai\Message\FoldedMessage || $message instanceof \Switon\Ai\Message\ToolMessage) {
            if ($pending !== null) {
                $completions[] = \Switon\Ai\Completion::of($pending);
                $pending = null;
            }
            $completions[] = \Switon\Ai\Completion::of($message);
            continue;
        }

        if ($message instanceof \Switon\Ai\Message\AssistantMessage) {
            $completions[] = \Switon\Ai\Completion::of(
                $pending ?? \Switon\Ai\Message\UserMessage::of(''),
                $message,
            );
            $pending = null;
            continue;
        }

        if ($pending instanceof \Switon\Ai\Message\UserMessage && $message instanceof \Switon\Ai\Message\UserMessage) {
            $pending = \Switon\Ai\Message\UserMessage::of(trim($pending->wire()[0]['text'] . "\n\n" . $message->wire()[0]['text']));
            continue;
        }

        if ($pending !== null) {
            $completions[] = \Switon\Ai\Completion::of($pending);
        }
        $pending = $message;
    }

    if ($pending !== null) {
        $completions[] = \Switon\Ai\Completion::of($pending);
    }

    return $completions;
}

/**
 * @param list<\Switon\Ai\Completion> $completions
 *
 * @return list<\Switon\Ai\Message>
 */
function liveWireMessages(array $completions, ?\Switon\Ai\Completion $omit = null): array
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

/** Enable form_ask for profiles/directives that reference it (host fixture includes form-ask). */
/**
 * @param list<string>|null $types null = the full field-type list
 */
function liveBindFormAskTypes(ContainerInterface $container, ?array $types = null): void
{
    $types ??= [
        'input', 'text', 'textarea', 'number', 'email', 'tel', 'url', 'password',
        'select', 'radio', 'checkbox', 'switch', 'choice', 'slider', 'rate', 'tags',
        'date', 'datetime', 'time', 'month', 'range',
        'date-range', 'time-range', 'datetime-range', 'month-range', 'color',
    ];

    if (liveIsResolved($container, FormAskTool::class)) {
        $tool = $container->get(FormAskTool::class);
        (new ReflectionProperty(FormAskTool::class, 'types'))->setValue($tool, $types);
        (new ReflectionProperty(FormAskTool::class, 'schema'))->setValue($tool, null);

        return;
    }

    $container->replace(FormAskTool::class, [
        'class' => FormAskTool::class,
        'types' => $types,
    ]);
}

/** Test fixture profiles (host, lite, …) for stub-LLM live scripts. */
function liveRegisterStubProfiles(ContainerInterface $container): void
{
    $pathAlias = $container->get(PathAliasInterface::class);
    $fixtures = liveAgentPackageRoot() . '/tests/Fixtures';
    $pathAlias->set('@agency.test.agents', $fixtures . '/agents');
    liveAppendProfilePaths($container, [
        '@agency.test.agents',
        '@agency.test.agents/family',
        '@agency.test.agents/_ignored',
    ]);
}

/**
 * Extra profile dirs on the already-booted catalog (later paths override the same id).
 *
 * @param list<string> $paths
 */
function liveAppendProfilePaths(ContainerInterface $container, array $paths): void
{
    $discovery = $container->get(ProfileDiscoveryInterface::class);
    $prop = new ReflectionProperty($discovery, 'paths');
    $current = $prop->getValue($discovery);
    $prop->setValue($discovery, array_values(array_unique([...$current, ...$paths])));
    $discovery->discover();
}

/**
 * Extra skill dirs on the already-booted catalog.
 *
 * @param list<string> $paths
 */
function liveAppendSkillPaths(ContainerInterface $container, array $paths): void
{
    if (!liveIsResolved($container, SkillsInterface::class)) {
        $container->set(SkillsInterface::class, [
            'class' => \Switon\Skill\Skills::class,
            'paths' => new \ArrayIterator($paths),
        ]);

        return;
    }

    $skills = $container->get(SkillsInterface::class);
    $prop = new ReflectionProperty($skills, 'paths');
    $current = $prop->getValue($skills);
    $prop->setValue($skills, array_values(array_unique([...$current, ...$paths])));
    $skills->reload();
}

/** @return list<\Switon\Ai\Completion> */
function liveRuntimeClosureCompletions(\Switon\Ai\Thread $thread): array
{
    return array_values(array_filter(
        $thread->completions(),
        static fn (\Switon\Ai\Completion $c): bool => $c->input instanceof \Switon\Ai\Signal
            && $c->input instanceof \Switon\Ai\Signal\UserAbortSignal,
    ));
}

function liveSignalInCompletion(\Switon\Ai\Completion $completion): ?Signal
{
    return $completion->input instanceof Signal ? $completion->input : null;
}

/**
 * Wire Langfuse when LANGFUSE_BASE_URL (+ keys) are set. Returns false if skipped.
 */
function liveBindLangfuse(ContainerInterface $container): bool
{
    $baseUrl = trim((string) (getenv('LANGFUSE_BASE_URL') ?: ''));
    if ($baseUrl === '') {
        return false;
    }

    liveRebind($container, \Switon\Langfuse\ClientInterface::class, [
        'class' => \Switon\Langfuse\Client::class,
        'baseUrl' => $baseUrl,
        'publicKey' => (string) (getenv('LANGFUSE_PUBLIC_KEY') ?: ''),
        'secretKey' => (string) (getenv('LANGFUSE_SECRET_KEY') ?: ''),
    ]);

    $provider = new \Switon\Langfuse\ServiceProvider();
    $container->get(InjectorInterface::class)->inject($provider);
    $provider->boot();

    echo "Langfuse: {$baseUrl}\n";

    return true;
}

function liveMessageContent(\Switon\Ai\Message $message): string
{
    if ($message instanceof \Switon\Ai\Message\ToolMessage) {
        $chunks = [];
        foreach ($message->results as $result) {
            $chunks[] = $result->content;
        }

        return implode("\n", $chunks);
    }

    return (string) ($message->wire()[0]['text'] ?? $message->content ?? '');
}

function liveToolMessageHasName(\Switon\Ai\Message\ToolMessage $message, string $name): bool
{
    foreach ($message->results as $result) {
        if ($result->name === $name) {
            return true;
        }
    }

    return false;
}

function liveFirstToolResult(\Switon\Ai\Message\ToolMessage $message): ?\Switon\Ai\ToolResult
{
    return $message->results[0] ?? null;
}

/** @param array<string, mixed> $row */
function liveAuditCompletionRowInput(array $row, callable $audit): int
{
    $input = $row['input'] ?? null;
    if (!is_array($input) || array_is_list($input)) {
        return 0;
    }

    $hits = $audit($input);
    foreach ($input['results'] ?? [] as $result) {
        if (is_array($result)) {
            $hits += $audit(['role' => \Switon\Ai\Message\ToolMessage::Role] + $result);
        }
    }

    return $hits;
}

/** @return list<array<string, mixed>> */
function liveTaskEventsInConversation(\Switon\Ai\Thread $thread): array
{
    $events = [];
    foreach (liveWireMessages($thread->completions()) as $message) {
        $event = liveTaskEventFromMessage($message);
        if ($event !== null) {
            $events[] = $event;
        }
    }

    return $events;
}
