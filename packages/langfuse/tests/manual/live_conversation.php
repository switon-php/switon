<?php

declare(strict_types=1);

/**
 * End-to-end replay against a live Langfuse: real container, real listeners, real OTLP export.
 *
 *   LANGFUSE_BASE_URL=http://host/langfuse \
 *   LANGFUSE_PUBLIC_KEY=pk-lf-... \
 *   LANGFUSE_SECRET_KEY=sk-lf-... \
 *   php packages/langfuse/tests/manual/live_conversation.php
 *
 * Prints the exported trace id so it can be read back through the Langfuse API.
 */

use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface as PsrListenerProviderInterface;
use Psr\Log\LoggerInterface;
use Switon\Ai\AgentInterface;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Event\TurnSuspended;
use Switon\Ai\LlmTiming;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Suspension\ToolReplyInterrupt;
use Switon\Ai\Thread;
use Switon\Ai\TokenUsage;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Ai\ToolSchema;
use Switon\Ai\ToolTiming;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Core\App;
use Switon\Core\ContextManagerInterface;
use Switon\Core\InjectorInterface;
use Switon\Di\Container as DiContainer;
use Switon\Di\ServiceProvider as DiServiceProvider;
use Switon\Eventing\EventDispatcher as RealEventDispatcher;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Langfuse\Client;
use Switon\Langfuse\ClientInterface;
use Switon\Langfuse\Observability\LlmListener;
use Switon\Llm\Event\CompletionResponded;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\InMemoryContextManager;
use Switon\Testing\MockLogger;
use Switon\Tooling\Event\ToolFinished;
use Switon\Tooling\ToolsInterface;

require dirname(__DIR__, 4) . '/vendor/autoload.php';
require dirname(__DIR__, 4) . '/tests/bootstrap.php';

$baseUrl = getenv('LANGFUSE_BASE_URL') ?: '';
$publicKey = getenv('LANGFUSE_PUBLIC_KEY') ?: '';
$secretKey = getenv('LANGFUSE_SECRET_KEY') ?: '';
if ($baseUrl === '' || $publicKey === '' || $secretKey === '') {
    console_log('error', 'Set LANGFUSE_BASE_URL, LANGFUSE_PUBLIC_KEY, LANGFUSE_SECRET_KEY');

    exit(1);
}

$container = new DiContainer();
$di = new DiServiceProvider();
$di->register($container);
$di->boot();
App::setContainer($container);
$container->set(ContextManagerInterface::class, InMemoryContextManager::class);

$realDispatcher = $container->make(RealEventDispatcher::class);
$eventDispatcher = new TestEventDispatcher($realDispatcher);
$container->set(EventDispatcherInterface::class, $eventDispatcher);
$container->set(PsrEventDispatcherInterface::class, $eventDispatcher);
$container->set(PsrListenerProviderInterface::class, ListenerProviderInterface::class);

$logger = new MockLogger();
$container->set(LoggerInterface::class, $logger);
$container->set(ClientInterface::class, [
    'class' => Client::class,
    'baseUrl' => $baseUrl,
    'publicKey' => $publicKey,
    'secretKey' => $secretKey,
]);

$injector = $container->get(InjectorInterface::class);
$listener = new LlmListener();
$injector->inject($listener);

/** A stub LLM whose wire model name is what Langfuse should show. */
$llm = new class () implements Switon\Ai\LlmInterface {
    public function complete(Thread $thread, Charter $charter): Completion
    {
        return $thread->lastCompletion();
    }

    public function stream(Thread $thread, Charter $charter, ?callable $onDelta = null, ?callable $shouldAbort = null): Completion
    {
        return $this->complete($thread, $charter);
    }

    public function model(): Switon\Ai\Model
    {
        return Switon\Ai\Model::of(model: 'claude-sonnet-4-6', window: 200_000);
    }

    public function window(): int
    {
        return 200_000;
    }
};

$thread = new Thread('9c41f0b2-6d7e-4f18-a3c5-2b8e17d640af');
$thread->state['profileId'] = 'release-notes.answer';
$turnId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
$charter = Charter::of(
    prompt: 'You are a concise release-notes assistant.',
    tools: [
        'web_search' => new ToolSchema(
            description: 'Search the web',
            properties: ['query' => ['type' => 'string']],
            required: ['query'],
        ),
    ],
    toolChoice: 'auto',
);

// Step 1: the model asks for a tool call.
$call = new ToolCall('call_live_0001', 'web_search', [
    '.caption' => 'Search release notes',
    'query' => 'switon langfuse 1.0',
]);
$step1 = Completion::of(UserMessage::of('Summarize the Langfuse 1.0 release notes.'));
$step1->output = new AssistantMessage(null, [$call], reasoning: 'Need fresh release notes first.');
$step1->usage = new TokenUsage(input: 1_842, output: 96);
$step1->timing = LlmTiming::of(wall: 1.75, ttft: 0.42, start: microtime(true) - 6.0);
$step1->step = 1;
$step1->attributes = ['tenant_id' => 'acme', 'feature' => 'release-notes'];
$step1->usage = new TokenUsage(input: 1_842, output: 96, cacheRead: 1_200, hit: 0.65, ideal: 0.92);

$turn = Turn::of($turnId, [$step1], TurnStatus::Running, 'claude-sonnet-4-6', effort: 'high');
$thread->turns[] = $turn;

console_log('info', 'step 1: tool-calling generation → {trace}', ['trace' => $turnId]);
$listener->onCompletionResponded(new CompletionResponded($llm, $thread, $step1, $charter, stream: true));

// Step 2: the tool runs and returns.
$result = new ToolResult(
    '{"results":[{"title":"Langfuse 1.0","url":"https://example.test/release"}]}',
    $call->id,
    $call->name,
    ToolTiming::of($call, 0.83),
);

$stub = new class ('probe') extends PHPUnit\Framework\TestCase {
    public function makeStub(): AgentInterface
    {
        return $this->createStub(AgentInterface::class);
    }
};
$agent = $stub->makeStub();
$agent->method('thread')->willReturn($thread);
$agent->method('threadId')->willReturn($thread->id);

console_log('info', 'step 2: tool span under that generation');
$listener->onToolFinished(new ToolFinished($container->get(ToolsInterface::class), $call, $agent, $result));

// Step 3: a second completion consumes the tool result, with cache hits so usage buckets split.
$step2 = Completion::of(ToolMessage::of([$result]));
$step2->output = new AssistantMessage(
    "Langfuse 1.0 ships OTLP export: traces are spans, tool calls nest under their generation, "
    . "and usage is reported in exclusive cache/input/output buckets.",
);
$step2->usage = new TokenUsage(input: 2_960, output: 174, cacheRead: 2_400, reasoning: 40);
$step2->timing = LlmTiming::of(wall: 2.4, ttft: 0.31, reasoning: 0.6, start: microtime(true) - 3.0);
$step2->finishReason = 'end_turn';
$step2->step = 2;
$turn->completions[] = $step2;

console_log('info', 'step 3: reply generation with cache usage');
$listener->onCompletionResponded(new CompletionResponded($llm, $thread, $step2, $charter, stream: true));

// Step 4: a suspended tool on its own span id, so it cannot collide with a later real run.
$askCall = new ToolCall('call_live_0002', 'ask_user', ['question' => 'Which release channel?']);
$step3 = Completion::of(ToolMessage::of([
    new ToolResult('done', 'call_live_0001', 'web_search'),
]));
$step3->output = new AssistantMessage(null, [$askCall]);
$step3->step = 3;
$turn->completions[] = $step3;
$interrupt = ToolReplyInterrupt::of($askCall);
$interrupt->suspendedAt = microtime(true);

console_log('info', 'step 4: suspended tool span');
$listener->onTurnSuspended(new TurnSuspended($agent, $turn, $interrupt));

if ($logger->logs !== []) {
    foreach ($logger->logs as $log) {
        console_log('warning', $log['message'], $log['context']);
    }

    exit(1);
}

console_log('info', 'OK — read back with trace id {trace}', [
    'trace' => $turnId,
]);
