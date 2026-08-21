<?php

declare(strict_types=1);

/**
 * Coding agent run with Langfuse tracing.
 *
 *   LANGFUSE_BASE_URL=... LANGFUSE_PUBLIC_KEY=... LANGFUSE_SECRET_KEY=... \
 *   DEMO_MODEL=best php packages/langfuse/tests/manual/coding_run.php "task text"
 *
 * Boots the real coding desk (orchestrator + agent_* sub-agents) with Langfuse wired, drains the
 * delegation tree, then reports what the trace should contain.
 */
$repoRoot = dirname(__DIR__, 4);
require $repoRoot . '/vendor/autoload.php';
require $repoRoot . '/tests/bootstrap.php';
switon_register_monorepo_test_autoloads($repoRoot . '/packages/agency', $repoRoot);

use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface as PsrListenerProviderInterface;
use Psr\Log\LoggerInterface;
use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Ai\Envelope;
use Switon\Ai\LlmInterface;
use Switon\Core\App;
use Switon\Core\ContextManagerInterface;
use Switon\Core\InjectorInterface;
use Switon\Core\PathAliasInterface;
use Switon\Di\Container;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Eventing\EventDispatcher;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Langfuse\Client;
use Switon\Langfuse\ClientInterface;
use Switon\Langfuse\ServiceProvider as LangfuseProvider;
use Switon\Llm\LlmDiscovery;
use Switon\Llm\LlmDiscoveryInterface;
use Switon\Llm\LlmProviders;
use Switon\Llm\LlmProvidersInterface;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\InMemoryContextManager;
use Switon\Testing\MockLogger;

$baseUrl = getenv('LANGFUSE_BASE_URL') ?: '';
$publicKey = getenv('LANGFUSE_PUBLIC_KEY') ?: '';
$secretKey = getenv('LANGFUSE_SECRET_KEY') ?: '';
if ($baseUrl === '' || $publicKey === '' || $secretKey === '') {
    console_log('error', 'Set LANGFUSE_BASE_URL, LANGFUSE_PUBLIC_KEY, LANGFUSE_SECRET_KEY');

    exit(1);
}

$task = ($argv[1] ?? '') !== '' ? $argv[1] : <<<'PROMPT'
Do this through the coding pipeline, keeping scope tight:

1. coding.explore — find where `TraceId` / `traceId` is produced in `packages/langfuse/src/Otlp.php`, and report the exact line.
2. coding.verify — state whether a unit test covers it (search `packages/langfuse/tests`).

Constraints: read-only. Do not edit files, do not commit.
Report: the file, the line, and whether a test exists.
PROMPT;

chdir($repoRoot);

$container = new Container();
$di = new DiServiceProvider();
$di->register($container);
$di->boot();
App::setContainer($container);
$container->set(ContextManagerInterface::class, InMemoryContextManager::class);

$realDispatcher = $container->make(EventDispatcher::class);
$eventDispatcher = new TestEventDispatcher($realDispatcher);
$container->set(EventDispatcherInterface::class, $eventDispatcher);
$container->set(PsrEventDispatcherInterface::class, $eventDispatcher);
$container->set(PsrListenerProviderInterface::class, ListenerProviderInterface::class);
$container->set(LoggerInterface::class, new MockLogger());

$container->replace(PsrEventDispatcherInterface::class, $eventDispatcher);
$container->replace(LlmProvidersInterface::class, LlmProviders::class);
$container->replace(LlmDiscoveryInterface::class, LlmDiscovery::class);
$container->get(LlmDiscoveryInterface::class)->discover();

$wanted = getenv('DEMO_MODEL') ?: 'best';
$providers = $container->get(LlmProvidersInterface::class);
if (!$providers->has($wanted)) {
    console_log('error', 'Model alias {name} missing from the catalog.', ['name' => $wanted]);

    exit(1);
}
$container->replace(LlmInterface::class, $providers->get($wanted));

$container->replace(ClientInterface::class, [
    'class' => Client::class,
    'baseUrl' => $baseUrl,
    'publicKey' => $publicKey,
    'secretKey' => $secretKey,
]);

// The coding desk resolves profiles from @agents plus the package resources.
$container->replace(Switon\Ai\PromptRendererInterface::class, Switon\Agents\PromptRenderer::class);
$pathAlias = $container->get(PathAliasInterface::class);
$runtime = $repoRoot . '/runtime';
$pathAlias->set('@threads', $runtime . '/threads');
$pathAlias->set('@runtime', $runtime);

$agency = new Switon\Agency\ServiceProvider();
$container->get(InjectorInterface::class)->inject($agency);
$agency->register($container);
$agency->boot();

// The coding desk publishes its profiles from resources/agents; composer-extra normally wires this,
// but a standalone script has to add the directory to the profile scan path.
$deskAgents = $repoRoot . '/packages/desk-coding/resources/agents';
// ResourceAlias registration normally runs at kernel boot; a standalone script wires the aliases
// the coding desk needs: @agents (shared segments/skills) and the desk's own agents directory.
$pathAlias->set('@agents', $repoRoot . '/packages/agents/resources/agents');
$pathAlias->set('@desks', $repoRoot . '/packages/agents/resources/desks');
$pathAlias->set('@desk-coding/agents', $deskAgents);

$langfuse = new LangfuseProvider();
$container->get(InjectorInterface::class)->inject($langfuse);
$langfuse->boot();

console_log('info', 'model={model} workspace={ws}', [
    'model' => $container->get(LlmInterface::class)->model()->model,
    'ws' => $repoRoot,
]);

$profiles = $container->get(Switon\Agents\ProfilesInterface::class);
// Append the desk directory to the scan paths, then reload the catalog.
$pathsProp = new ReflectionProperty($profiles, 'paths');
$current = $pathsProp->getValue($profiles);
$pathsProp->setValue($profiles, array_values(array_unique([...$current, $deskAgents])));
$profiles->reload();

$leadProfile = $profiles->get('agent');

$manager = $container->get(RecordingAgentManager::class);
$lead = $manager->mint(['profile' => $leadProfile, 'workspace' => $repoRoot]);
console_log('info', 'thread={thread}', ['thread' => $lead->threadId()]);

if (extension_loaded('swoole')) {
    \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    \Swoole\Coroutine\run(static function () use ($lead, $task, $container, $manager): void {
        // mint() already started the inbox worker; calling start() again spawns a second consumer
        // on the same channel and both block.
        $lead->deliver(Envelope::of($task, $lead->profile()->model));

        // The orchestrator delegates; wait until its own turn settles and sub-agents go quiet.
        $deadline = microtime(true) + 600.0;
        $lastChange = microtime(true);
        $lastCount = 0;
        while (microtime(true) < $deadline) {
            \Swoole\Coroutine::sleep(0.5);
            $count = count($lead->thread()->completions());
            if ($count !== $lastCount) {
                $lastCount = $count;
                $lastChange = microtime(true);
            }
            $turn = $lead->thread()->turns === [] ? null : $lead->thread()->lastTurn();
            $settled = $turn !== null && $turn->status->settled();
            if ($settled && (microtime(true) - $lastChange) > 6.0) {
                break;
            }
        }

        // Flush telemetry before tearing the agent tree down: agents still hold queued exports.
        $container->get(ClientInterface::class)->shutdown(20.0);
        // shutdown() pushes onto the inbox channel, so it must run inside the coroutine.
        $manager->stopTrackedAgents();
    });
} else {
    console_log('error', 'Swoole is required: the agent inbox is a coroutine channel.');

    exit(1);
}

console_log('info', 'done: {n} turns in host thread', ['n' => count($lead->thread()->turns)]);
