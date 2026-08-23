<?php

declare(strict_types=1);

/**
 * Live probe: empty stub tool_call args + short ToolResult(argument error) in history,
 * then a real LLM turn — does the provider accept wire "{}" and can the model retry?
 *
 *   php packages/agency/tests/manual/live_tool_parse_failed_as_result.php
 */

use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Ai\TurnStatus;
use Switon\Thread\ThreadsInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Llm\Event\CompletionRequesting;
use Switon\Llm\LlmProvidersInterface;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Ai\Envelope;

require __DIR__ . '/live_bootstrap.php';

\Switon\Cli\Runner::run(function (\Switon\Di\ContainerInterface $container): void {
liveBindTools($container, ['echo']);
liveRegisterAgentPackage($container);
liveEchoLlmSummary($container);

$dispatcher = $container->get(ListenerProviderInterface::class);

/** @var list<array<string, mixed>> $wiredToolCalls */
$wiredToolCalls = [];
$dispatcher->on(null, static function (CompletionRequesting $event) use (&$wiredToolCalls): void {
    foreach (liveWireMessages($event->thread->completions()) as $message) {
        if (!$message instanceof AssistantMessage) {
            continue;
        }
        foreach ($message->toolCalls as $call) {
            $wiredToolCalls[] = [
                'id' => $call->id,
                'name' => $call->name,
                'arguments' => $call->arguments,
            ];
        }
    }
});

/** @var \Switon\Agency\Agent $agent */
$agent = $container->get(RecordingAgentManager::class)->mint([
    'profile' => \Switon\Ai\Profile::decode([
        'id' => 'tool-parse-as-result-live',
        'prompt' => 'You have an echo tool. When a prior tool call failed because arguments were invalid JSON, '
            . 'retry echo with valid JSON: {"text":"hello"}. Do not explain; call the tool.',
        'tools' => ['echo'],
        'skills' => [],
        'model' => 'main',
        'max_steps' => 4,
    ]),
]);

$threads = $container->get(ThreadsInterface::class);
$thread = $agent->thread();
$runId = 'seed-parse-fail';
$turnId = $runId;
$callId = 'call_seed_invalid_args';

echo "=== live: stub empty args + argument ToolResult, then real LLM retry ===\n\n";

// Seed: user asked for echo → assistant announced tool with empty args (wire "{}") → tool failed parse.
$thread->turns[] = \Switon\Ai\Turn::of($turnId, status: TurnStatus::Running, model: 'main');
$completion2 = Completion::of(UserMessage::of('Call echo with text "hello".'), new AssistantMessage(
    'Calling echo.',
    [new ToolCall($callId, 'echo', [])],
));
$completion2->completeId = $runId . '-u';
$completion2->finishReason = 'tool_use';
$completion2->step = 1;
$completion2->landedAt = microtime(true);
$thread->lastTurn()->completions[] = $completion2;
$threads->saveTurn($thread);

$error = \Switon\Ai\Exception\ToolInvalidArgumentException::of(
    'Tool "echo" arguments are invalid JSON. Call the tool again with a valid JSON object.',
);
$completion = Completion::of(ToolMessage::of([
            new ToolResult(
                $error,
                $callId,
                'echo',
                attributes: ['partial_json_bytes' => 12],
            ),
        ]));
$completion->completeId = $runId . '-t';
$completion->step = 2;
$completion->landedAt = microtime(true);
$thread->lastTurn()->completions[] = $completion;
$threads->saveTurn($thread);

echo "--- seeded history ---\n";
echo "assistant tool_call: name=echo arguments=[] (wire \"{}\")\n";
echo 'tool result: ' . $error->getMessage() . "\n\n";

echo "--- real LLM follow-up ---\n";
try {
    liveRunInCoroutine(function () use ($agent): void {
        $agent->run(Envelope::of('Retry the failed echo call with valid JSON arguments text=hello.', liveModel($container, $agent->profile()->model)));
    });
} catch (Throwable $e) {
    fwrite(STDERR, 'PROVIDER/RUN ERROR: ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(2);
}

$echoRetries = 0;
foreach ($agent->thread()->turns as $_turn) {
    foreach ($_turn->completions as $row) {
        $output = $row->output;
        if (!$output instanceof AssistantMessage) {
            continue;
        }
        foreach ($output->toolCalls as $call) {
            if ($call->name !== 'echo' || $call->id === $callId) {
                continue;
            }
            $echoRetries++;
            echo "--- retry echo #{$echoRetries} args ---\n";
            echo json_encode($call->arguments, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        }
    }
}

$sawEmptyStub = false;
foreach ($wiredToolCalls as $call) {
    if (($call['id'] ?? '') === $callId && ($call['arguments'] ?? null) === []) {
        $sawEmptyStub = true;
        break;
    }
}

echo 'completions after run: ' . count($agent->thread()->completions()) . "\n";
foreach ($agent->thread()->completions() as $i => $row) {
    $in = $row->input::class;
    $out = $row->output === null ? 'null' : $row->output::class;
    $tools = $row->output instanceof AssistantMessage
        ? count($row->output->toolCalls)
        : 0;
    $snippet = '';
    if ($row->output instanceof AssistantMessage) {
        $snippet = substr(trim((string) $row->output->content), 0, 120);
    }
    echo "  [{$i}] in=" . basename(str_replace('\\', '/', $in))
        . " out=" . basename(str_replace('\\', '/', $out))
        . " tools={$tools} finish={$row->finishReason} text=" . json_encode($snippet, JSON_UNESCAPED_UNICODE) . "\n";
}

if (!$sawEmptyStub) {
    echo "NOTE: seeded empty-args call not seen in CompletionRequesting scan "
        . "(may still be on wire via conversation messages()).\n";
}

if ($echoRetries === 0) {
    echo "RESULT: FAIL — provider may have accepted history, but model did not retry echo.\n";
    exit(1);
}

echo "RESULT: OK — empty-args stub history accepted by provider; model retried echo {$echoRetries} time(s).\n";
});
