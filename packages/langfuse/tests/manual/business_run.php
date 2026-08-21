<?php

declare(strict_types=1);

/**
 * Business-shaped agent run: several turns in one thread, a failing tool, and a long tool chain.
 *
 *   LANGFUSE_BASE_URL=... LANGFUSE_PUBLIC_KEY=... LANGFUSE_SECRET_KEY=... \
 *   php packages/langfuse/tests/manual/business_run.php
 *
 * What it checks on the Langfuse side:
 * - one session id across turns, one trace per turn
 * - every step present, no gaps, generations paired with their tool calls
 * - a failing tool surfaced as ERROR without breaking the turn
 * - settled status and closed_at written for each turn
 */
$repoRoot = dirname(__DIR__, 4);
require $repoRoot . '/vendor/autoload.php';
require $repoRoot . '/tests/bootstrap.php';
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
use Switon\Llm\Llm;
use Switon\Llm\LlmDiscovery;
use Switon\Llm\LlmDiscoveryInterface;
use Switon\Llm\LlmProviders;
use Switon\Llm\LlmProvidersInterface;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\InMemoryContextManager;
use Switon\Testing\MockLogger;
use Switon\Tooling\ServiceProvider as ToolingProvider;
use Switon\Tooling\Tool\BashTool;
use Switon\Tooling\Tool\GlobTool;
use Switon\Tooling\Tool\GrepTool;
use Switon\Tooling\Tool\LsTool;
use Switon\Tooling\Tool\ReadTool;
use Switon\Tooling\Tool\WriteTool;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;

$baseUrl = getenv('LANGFUSE_BASE_URL') ?: '';
$publicKey = getenv('LANGFUSE_PUBLIC_KEY') ?: '';
$secretKey = getenv('LANGFUSE_SECRET_KEY') ?: '';
if ($baseUrl === '' || $publicKey === '' || $secretKey === '') {
    console_log('error', 'Set LANGFUSE_BASE_URL, LANGFUSE_PUBLIC_KEY, LANGFUSE_SECRET_KEY');

    exit(1);
}

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

$tooling = new ToolingProvider();
$container->get(InjectorInterface::class)->inject($tooling);
$tooling->register($container);
$tooling->boot();

$container->replace(ToolsInterface::class, [
    'class' => Tools::class,
    'tools' => [
        'bash' => BashTool::class,
        'read' => ReadTool::class,
        'write' => WriteTool::class,
        'grep' => GrepTool::class,
        'glob' => GlobTool::class,
        'ls' => LsTool::class,
    ],
]);

$container->replace(PsrEventDispatcherInterface::class, $eventDispatcher);
$container->replace(LlmProvidersInterface::class, LlmProviders::class);
$container->replace(LlmDiscoveryInterface::class, LlmDiscovery::class);
$container->get(LlmDiscoveryInterface::class)->discover();

// `Llm` is a factory for the *first* catalog client, so bind the catalog alias explicitly:
// `Switon\Llm\LlmProvidersInterface::get('best')` resolves ~/.switon/llm.yml `aliases.best`.
$wanted = getenv('DEMO_MODEL') ?: 'best';
$providers = $container->get(LlmProvidersInterface::class);
if (!$providers->has($wanted)) {
    console_log('error', 'Model alias {name} is not in the catalog; available: {names}', [
        'name' => $wanted,
        'names' => implode(', ', array_keys(iterator_to_array($providers->all()))),
    ]);

    exit(1);
}
$container->replace(LlmInterface::class, $providers->get($wanted));

$container->replace(ClientInterface::class, [
    'class' => Client::class,
    'baseUrl' => $baseUrl,
    'publicKey' => $publicKey,
    'secretKey' => $secretKey,
]);

$langfuse = new LangfuseProvider();
$container->get(InjectorInterface::class)->inject($langfuse);
$langfuse->boot();

// Charters need a prompt renderer; agents owns the implementation.
$container->replace(\Switon\Ai\PromptRendererInterface::class, \Switon\Agents\PromptRenderer::class);

// Agency owns the loop, charters, and extensions the Agent needs.
$agency = new \Switon\Agency\ServiceProvider();
$container->get(InjectorInterface::class)->inject($agency);
$agency->register($container);
$agency->boot();

$runtime = $repoRoot . '/runtime';
$container->get(PathAliasInterface::class)->set('@threads', $runtime . '/threads');
$container->get(PathAliasInterface::class)->set('@runtime', $runtime);

// Prompts are read by the model and tools as a POSIX-looking path, so normalize separators: on
// Windows `dirname()` hands back backslashes and `{$root}/packages/...` would mix both styles.
$promptRoot = strtr($repoRoot, '\\', '/');

$profile = new Profile(
    id: 'ops-triage',
    description: 'Triages a repository and reports operational facts',
    prompt: <<<PROMPT
You are an operations assistant working inside a repository.

The workspace root is {$promptRoot}.
- Tools require an absolute path: pass `{$promptRoot}/packages/langfuse/src`, not `packages/langfuse/src`.
- Omit `cwd` entirely. Never call `pwd`, `ls ~`, or `find /` to locate the repository.

Facts must come from tools, never memory. If a tool fails, say what failed and continue.
Keep each answer under three lines. Reply in the user's language.
PROMPT,
    tools: ['bash', 'read', 'write', 'grep', 'glob', 'ls'],
    skills: [],
    model: 'best',   // MiniMax-M3
    maxSteps: 10,
);

$manager = $container->get(RecordingAgentManager::class);
/** @var Agent $agent */
$agent = $manager->mint(['profile' => $profile, 'workspace' => $repoRoot]);

console_log('info', 'session (thread) = {thread}', ['thread' => $agent->threadId()]);
console_log('info', 'model = {model}  workspace = {workspace}', [
    'model' => $container->get(LlmInterface::class)->model()->model,
    'workspace' => $repoRoot,
]);

$prompts = [
    'How many PHP files are there under packages/langfuse/src? Count them with a tool.',
    'Read packages/langfuse/src/Otlp.php and tell me the name of the method that builds a 32-char trace id.',
    'Now read /tmp/this-file-does-not-exist.txt and report exactly what happened.',
];

if (extension_loaded('swoole')) {
    \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    \Swoole\Coroutine\run(static function () use ($agent, $prompts, $manager, $container): void {
        foreach ($prompts as $index => $prompt) {
            // Wait for a *new* turn, not the previous one: the last turn is already settled when the
            // next delivery is queued, so checking `settled()` alone returns immediately.
            $before = count($agent->thread()->turns);
            $agent->deliver(Envelope::of($prompt, $agent->profile()->model));

            $deadline = microtime(true) + 180.0;
            while (microtime(true) < $deadline) {
                \Swoole\Coroutine::sleep(0.3);
                $turns = $agent->thread()->turns;
                if (count($turns) <= $before) {
                    continue;
                }
                $turn = $turns[array_key_last($turns)];
                if ($turn->status->settled()) {
                    break;
                }
            }

            console_log('info', 'turn {n} settled at {at}', [
                'n' => $index + 1,
                'at' => date('H:i:s'),
            ]);
        }

        $container->get(ClientInterface::class)->shutdown(15.0);
        $manager->stopTrackedAgents();
    });
} else {
    foreach ($prompts as $prompt) {
        $agent->deliver(Envelope::of($prompt, $agent->profile()->model));
    }
    $container->get(ClientInterface::class)->shutdown(15.0);
    $manager->stopTrackedAgents();
}

console_log('info', 'done: {turns} turns', ['turns' => count($agent->thread()->turns)]);
