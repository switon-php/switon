<?php

declare(strict_types=1);

/**
 * Live agent demo for inspecting the Langfuse trace rendering.
 *
 *   LANGFUSE_BASE_URL=... LANGFUSE_PUBLIC_KEY=... LANGFUSE_SECRET_KEY=... \
 *   php packages/langfuse/tests/manual/agent_demo.php
 *
 * The task is a real code investigation: it needs bash, glob, grep and read, so the trace exercises
 * several tool types, a deliberate failure, and a multi-step agent loop.
 */
$repoRoot = dirname(__DIR__, 4);
require $repoRoot . '/vendor/autoload.php';
require $repoRoot . '/tests/bootstrap.php';
// Agency test fixtures (RecordingAgentManager) live under autoload-dev, which the root
// autoloader does not expose.
switon_register_monorepo_test_autoloads($repoRoot . '/packages/agency', $repoRoot);

use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface as PsrListenerProviderInterface;
use Psr\Log\LoggerInterface;
use Switon\Agency\Agent;
use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Ai\Envelope;
use Switon\Ai\LlmInterface;
use Switon\Ai\Profile;
use Switon\Core\App;
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
use Switon\Llm\Llm;
use Switon\Llm\LlmDiscovery;
use Switon\Llm\LlmDiscoveryInterface;
use Switon\Llm\LlmProviders;
use Switon\Llm\LlmProvidersInterface;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\InMemoryContextManager;
use Switon\Testing\MockLogger;
use Switon\Tooling\ServiceProvider as ToolingProvider;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;

$baseUrl = getenv('LANGFUSE_BASE_URL') ?: '';
$publicKey = getenv('LANGFUSE_PUBLIC_KEY') ?: '';
$secretKey = getenv('LANGFUSE_SECRET_KEY') ?: '';
if ($baseUrl === '' || $publicKey === '' || $secretKey === '') {
    console_log('error', 'Set LANGFUSE_BASE_URL, LANGFUSE_PUBLIC_KEY, LANGFUSE_SECRET_KEY');

    exit(1);
}

$task = $argv[1] ?? <<<'PROMPT'
Investigate this repository and report the answer. Use the tools, never guess.

1. `bash`: run `git log --oneline -3` and note the latest commit subject.
2. `glob`: list `packages/langfuse/src/**/*.php`.
3. `grep`: find which file declares `function traceId`.
4. `read`: open that file and quote the line producing a 32-character trace id.
5. `read` once on `/tmp/definitely-missing-file.txt` so the failure path is recorded.

Answer in at most five lines: latest commit, how many source files, which file owns traceId, and what the missing-file call returned.
PROMPT;

$container = new Container();
$di = new DiServiceProvider();
$di->register($container);
$di->boot();
App::setContainer($container);
$container->set(\Switon\Core\ContextManagerInterface::class, InMemoryContextManager::class);

$realDispatcher = $container->make(EventDispatcher::class);
$eventDispatcher = new TestEventDispatcher($realDispatcher);
$container->set(EventDispatcherInterface::class, $eventDispatcher);
$container->set(PsrEventDispatcherInterface::class, $eventDispatcher);
$container->set(PsrListenerProviderInterface::class, ListenerProviderInterface::class);

$logger = new MockLogger();
$container->set(LoggerInterface::class, $logger);

$tooling = new ToolingProvider();
$container->get(InjectorInterface::class)->inject($tooling);
$tooling->register($container);
$tooling->boot();

// Map of tool id => implementation; a plain list would be read as service ids.
$container->replace(ToolsInterface::class, [
    'class' => Tools::class,
    'tools' => [
        'bash' => \Switon\Tooling\Tool\BashTool::class,
        'read' => \Switon\Tooling\Tool\ReadTool::class,
        'write' => \Switon\Tooling\Tool\WriteTool::class,
        'grep' => \Switon\Tooling\Tool\GrepTool::class,
        'glob' => \Switon\Tooling\Tool\GlobTool::class,
        'ls' => \Switon\Tooling\Tool\LsTool::class,
        'lint' => \Switon\Tooling\Tool\LintTool::class,
    ],
]);

// Real provider catalog from ~/.switon/llm.yml.
$container->replace(LlmProvidersInterface::class, LlmProviders::class);
$container->replace(LlmDiscoveryInterface::class, LlmDiscovery::class);
$container->get(LlmDiscoveryInterface::class)->discover();
$container->replace(LlmInterface::class, Llm::class);

$container->set(ClientInterface::class, [
    'class' => Client::class,
    'baseUrl' => $baseUrl,
    'publicKey' => $publicKey,
    'secretKey' => $secretKey,
]);

$langfuse = new LangfuseProvider();
$container->get(InjectorInterface::class)->inject($langfuse);
$langfuse->boot();

$container->replace(\Switon\Ai\PromptRendererInterface::class, \Switon\Agents\PromptRenderer::class);

// Agency owns the loop, charters, and prompt renderer the Agent needs.
$agency = new \Switon\Agency\ServiceProvider();
$container->get(InjectorInterface::class)->inject($agency);
$agency->register($container);
$agency->boot();

$runtime = dirname(__DIR__, 4) . '/runtime';
$container->get(PathAliasInterface::class)->set('@threads', $runtime . '/threads');
$container->get(PathAliasInterface::class)->set('@runtime', $runtime);

// Confirm the listener really is registered before the agent runs.
$probe = new \Switon\Ai\Event\DeliverySettled(
    (new class ('p') extends PHPUnit\Framework\TestCase {
        public function mk(): \Switon\Ai\AgentInterface
        {
            return $this->createStub(\Switon\Ai\AgentInterface::class);
        }
    })->mk(),
    \Switon\Ai\Turn::of('probe'),
);
$registered = $container->get(ListenerProviderInterface::class)->getListenersForEvent($probe);
$flat = [];
foreach ($registered->getGroups() as $group) {
    foreach ($group as $listener) {
        $flat[] = is_array($listener) ? ($listener[0]::class . '::' . ($listener[1] ?? '?')) : get_debug_type($listener);
    }
}
console_log('info', 'DeliverySettled listeners: {n} {list}', ['n' => count($flat), 'list' => implode(', ', $flat)]);

console_log('info', 'Langfuse: {url}', ['url' => $baseUrl]);
console_log('info', 'Model: {model}', ['model' => $container->get(LlmInterface::class)->model()->model]);

$profile = new Profile(
    id: 'code-investigator',
    description: 'Reads a repository and reports findings',
    prompt: <<<'PROMPT'
You are a precise repository investigator. Use the provided tools for every fact — never answer from memory.
Prefer one focused tool call per question. If a tool fails, record what happened and continue.
Answer in the user's language, at most five lines.
PROMPT,
    tools: ['bash', 'read', 'write', 'grep', 'glob', 'ls', 'lint'],
    skills: [],
    model: 'main',
    maxSteps: 12,
);

$manager = $container->get(RecordingAgentManager::class);
/** @var Agent $agent */
// The workspace is what relative tool paths resolve against; without it grep fails on ".".
$agent = $manager->mint(['profile' => $profile, 'workspace' => dirname(__DIR__, 4)]);
console_log('info', 'thread={thread}', ['thread' => $agent->threadId()]);

// The agent inbox is a Swoole channel, so the run must happen inside a coroutine.
if (extension_loaded('swoole')) {
    // Production setting: curl is hooked, so exports yield instead of blocking. The component's
    // ExportQueue keeps them FIFO, which is what keeps every step on the trace.
    \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    \Swoole\Coroutine\run(static function () use ($agent, $task, $manager, $container): void {
        $agent->deliver(Envelope::of($task, 'main'));
        // Wait for the turn to settle, not merely for the inbox to drain: `DeliverySettled` is what
        // writes the final status, and `isIdle()` can flip before the loop seals the turn.
        $deadline = microtime(true) + 180.0;
        $sawRun = false;
        while (microtime(true) < $deadline) {
            \Swoole\Coroutine::sleep(0.3);
            $turn = $agent->thread()->turns === [] ? null : $agent->thread()->lastTurn();
            if ($turn !== null && $turn->status->settled()) {
                $sawRun = true;
                break;
            }
            if ($turn !== null) {
                $sawRun = true;
            }
        }
        // Give the settle export a moment to flush before tearing the agent down.
        \Swoole\Coroutine::sleep(1.0);
        // Exports are queued under coroutine hooks; stop intake, drain, and let the worker exit.
        $container->get(ClientInterface::class)->shutdown(10.0);
        if (!$sawRun) {
            echo "warning: no turn observed\n";
        }
        $manager->stopTrackedAgents();
    });
} else {
    $agent->deliver(Envelope::of($task, 'main'));
    $manager->stopTrackedAgents();
}

console_log('info', 'OK');
