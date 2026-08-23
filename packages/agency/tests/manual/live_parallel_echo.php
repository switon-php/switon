<?php

declare(strict_types=1);

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Tooling\AbstractTool;
use Switon\Tooling\Event\ToolStarted;
use Switon\Thread\ThreadsInterface;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Llm\Event\CompletionResponded;
use Switon\Ai\Message;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\ToolSchema;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;
use Switon\Agency\Agent;
use Switon\Ai\Envelope;
use Switon\Ai\Thread;

require __DIR__ . '/live_bootstrap.php';

/** Collects dispatched events. The kernel dispatcher has no of(). */
class LiveEventLog
{
    /** @var list<object> */
    public array $events = [];

    /** @return list<object> */
    public function of(string $class): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (object $event): bool => $event instanceof $class,
        ));
    }
}

/** Probe-only echo schema: distinct integer `n` per parallel call. */
class ParallelEchoTool extends AbstractTool
{
    public function schema(string $name): ToolSchema
    {
        return new ToolSchema(
            $this,
            description: 'Echo tool arguments back unchanged.',
            properties: [
                'n' => ['type' => 'integer', 'description' => 'Sequence number for this echo call.'],
            ],
            required: ['n'],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        return $arguments;
    }
}

$mode = $argv[1] ?? 'task';

function makeAgent(ContainerInterface $container, int $loopLimit = 3): Agent
{
    return $container->get(RecordingAgentManager::class)->mint([
        'profile' => new \Switon\Ai\Profile(
            id: 'parallel-echo',
            prompt: 'You follow tool-call instructions precisely. Use only the echo tool for this task.',
            tools: ['echo'],
            skills: [],
            model: 'main',
            maxSteps: $loopLimit,
        ),
    ]);
}

function taskPrompt(int $total): string
{
    return <<<PROMPT
Task: call the echo tool exactly {$total} times in total.

Rules:
- Use echo only; each call must have a distinct integer "n" from 1 to {$total}.
- Maximize parallel tool_calls per assistant step — batch as many echo calls as you can in one response.
- Continue across steps until all {$total} calls are done; do not skip or duplicate "n".
- Do not send a final text reply until every echo call from 1 to {$total} has completed.

When finished, reply with one line: DONE total={$total} steps=<tool-call steps used>.
PROMPT;
}

/** @return array{total: int, steps: list<int>, reply: string, error: ?string} */
function runTask(
    ContainerInterface $container,
    Agent $agent,
    LiveEventLog $dispatcher,
    ListenerProviderInterface $listenerProvider,
    string $prompt,
): array {
    $step = 0;
    $cumulative = 0;
    /** @var list<int> $parallelPerStep */
    $parallelPerStep = [];

    $listenerProvider->on(null, static function (CompletionResponded $event) use (&$step, &$cumulative, &$parallelPerStep): void {
        $parallel = 0;
        foreach ($event->completion->output?->toolCalls ?? [] as $toolCall) {
            if ($toolCall->name === 'echo') {
                $parallel++;
            }
        }

        $step++;
        if ($parallel > 0) {
            $cumulative += $parallel;
            $parallelPerStep[] = $parallel;
            echo sprintf(
                "  step %2d: parallel=%4d  cumulative=%4d  finish=%s\n",
                $step,
                $parallel,
                $cumulative,
                $event->completion->finishReason,
            );
        } else {
            echo sprintf("  step %2d: (text reply)     cumulative=%4d\n", $step, $cumulative);
        }
        if (function_exists('flush')) {
            flush();
        }
    });

    echo "  (waiting for model…)\n";
    if (function_exists('flush')) {
        flush();
    }

    $toolBefore = count($dispatcher->of(ToolStarted::class));
    $error = null;
    $reply = '';
    try {
        $agent->deliver(Envelope::of($prompt, liveModel($container, $agent->profile()->model)));
        $transcriptThread = Thread::of($agent->threadId(), $agent->thread()->datadir);
        $container->get(ThreadsInterface::class)->load($transcriptThread);
        foreach (liveWireMessages(
            $transcriptThread->completions(),
        ) as $message) {
            if ($message instanceof \Switon\Ai\Message\AssistantMessage) {
                $text = trim($message->content ?? '');
                if ($text !== '') {
                    $reply = $text;
                }
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $echoInvoked = 0;
    /** @var list<int> $ns */
    $ns = [];
    foreach (array_slice($dispatcher->of(ToolStarted::class), $toolBefore) as $event) {
        if ($event->call->name !== 'echo') {
            continue;
        }
        $echoInvoked++;
        $args = $event->call->arguments;
        if (is_array($args) && is_int($args['n'] ?? null)) {
            $ns[] = $args['n'];
        }
    }
    sort($ns);

    $missing = [];
    $expected = 0;
    if (preg_match('/exactly (\d+) times/', $prompt, $m)) {
        $expected = (int) $m[1];
        $have = array_fill(1, $expected, false);
        foreach ($ns as $n) {
            if ($n >= 1 && $n <= $expected) {
                $have[$n] = true;
            }
        }
        foreach ($have as $n => $seen) {
            if (!$seen) {
                $missing[] = $n;
            }
        }
    }

    $dupes = array_keys(array_filter(array_count_values($ns), static fn (int $c): bool => $c > 1));

    return [
        'total' => $echoInvoked,
        'steps' => $parallelPerStep,
        'reply' => $reply,
        'error' => $error,
        'ns' => $ns,
        'missing' => $missing,
        'dupes' => $dupes,
        'expected' => $expected,
    ];
}

function probePrompt(int $target): string
{
    if ($target > 0) {
        return <<<PROMPT
Call the echo tool exactly {$target} times **in parallel in a single assistant step** (one response with {$target} tool_calls, not sequential steps).

Rules:
- Each call must have a distinct integer "n" from 1 to {$target}.
- Do not write a text reply in the same step as the tool calls; tool calls only.

After tool results return, reply with one line: PARALLEL_COUNT=<number you attempted>.
PROMPT;
    }

    return <<<'PROMPT'
Call the echo tool as many times as you can **in parallel in a single assistant step** (one response with many tool_calls, not sequential steps).

Rules:
- Each call must have a distinct integer "n" starting at 1.
- Do not write a text reply in the same step as the tool calls; tool calls only.

After tool results return, reply with one line: PARALLEL_COUNT=<number you attempted>.
PROMPT;
}

/** @return array{parallel: int, ids: list<int>, finish: string, steps: int, reply: string, error: ?string} */
function runProbe(ContainerInterface $container, Agent $agent, LiveEventLog $dispatcher, string $prompt): array
{
    $respondedBefore = count($dispatcher->of(CompletionResponded::class));
    $toolBefore = count($dispatcher->of(ToolStarted::class));

    $error = null;
    $reply = '';
    try {
        $agent->deliver(Envelope::of($prompt, liveModel($container, $agent->profile()->model)));
        $transcriptThread = Thread::of($agent->threadId(), $agent->thread()->datadir);
        $container->get(ThreadsInterface::class)->load($transcriptThread);
        foreach (liveWireMessages(
            $transcriptThread->completions(),
        ) as $message) {
            if ($message instanceof \Switon\Ai\Message\AssistantMessage) {
                $text = trim($message->content ?? '');
                if ($text !== '') {
                    $reply = $text;
                }
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $newResponded = array_slice(
        $dispatcher->of(CompletionResponded::class),
        $respondedBefore,
    );
    $newTools = array_slice(
        $dispatcher->of(ToolStarted::class),
        $toolBefore,
    );

    $parallel = 0;
    $finish = '';
    $sampleArgs = [];
    foreach ($newResponded as $event) {
        $count = count($event->response->message->toolCalls);
        if ($count > $parallel) {
            $parallel = $count;
            $finish = $event->response->finishReason;
            $sampleArgs = array_map(
                static fn ($toolCall) => $toolCall->arguments,
                array_slice($event->response->message->toolCalls, 0, 3),
            );
        }
    }

    $ids = [];
    foreach ($newTools as $event) {
        if ($event->call->name !== 'echo') {
            continue;
        }
        $args = $event->call->arguments;
        if (is_array($args) && is_int($args['n'] ?? null)) {
            $ids[] = $args['n'];
        }
    }
    sort($ids);

    return [
        'parallel' => $parallel,
        'ids' => $ids,
        'finish' => $finish,
        'steps' => count($newResponded),
        'reply' => $reply,
        'error' => $error,
        'sampleArgs' => $sampleArgs,
    ];
}

function parseReportedCount(string $reply): ?int
{
    if (preg_match('/PARALLEL_COUNT\s*=\s*(\d+)/i', $reply, $m)) {
        return (int) $m[1];
    }

    return null;
}

echo "=== Parallel echo tool probe (Claude Code model) ===\n";

$taskTotal = isset($argv[2]) ? (int) $argv[2] : (int) (getenv('ECHO_TASK_TOTAL') ?: 1000);
$loopLimit = max(50, (int) ceil($taskTotal / 10) + 10);

Runner::run(function (ContainerInterface $container) use ($mode, $taskTotal, $loopLimit): void {
liveBindTools($container, new \ArrayIterator([
    'echo' => ParallelEchoTool::class,
]));
liveRegisterAgentPackage($container);
$dispatcher = new LiveEventLog();
$container->get(ListenerProviderInterface::class)->on(null, static function (object $event) use ($dispatcher): void {
    $dispatcher->events[] = $event;
});

$listenerProvider = $container->get(ListenerProviderInterface::class);

if ($mode === 'task') {
    $agent = makeAgent($container, $loopLimit);
    echo "Task mode: echo x{$taskTotal} (loop limit {$loopLimit})\n\n";
    echo "Per-step concurrency:\n";

    $out = runTask($container, $agent, $dispatcher, $listenerProvider, taskPrompt($taskTotal));

    echo "\n=== Summary ===\n";
    echo "Echo ToolStarted total: {$out['total']} / {$taskTotal}\n";
    echo 'Distinct n values:      ' . count(array_unique($out['ns'])) . "\n";
    if ($out['ns'] !== []) {
        echo 'n range:                ' . min($out['ns']) . '..' . max($out['ns']) . "\n";
    }
    if ($out['dupes'] !== []) {
        echo 'Duplicate n:            ' . implode(', ', array_slice($out['dupes'], 0, 10))
            . (count($out['dupes']) > 10 ? '...' : '') . "\n";
    }
    if ($out['missing'] !== []) {
        echo 'Missing n count:        ' . count($out['missing']) . "\n";
        echo 'Missing n sample:       ' . implode(', ', array_slice($out['missing'], 0, 20)) . "\n";
    } elseif ($out['expected'] > 0 && count($out['ns']) === $out['expected']) {
        echo "n audit:                1..{$out['expected']} all present ✓\n";
    }
    echo 'Tool-call steps:        ' . count($out['steps']) . "\n";
    if ($out['steps'] !== []) {
        echo 'Parallel per step:      min=' . min($out['steps'])
            . ' max=' . max($out['steps'])
            . ' avg=' . round(array_sum($out['steps']) / count($out['steps']), 1)
            . ' [' . implode(', ', $out['steps']) . "]\n";
    }
    if ($out['error'] !== null) {
        echo "Error:                  {$out['error']}\n";
    }
    echo "\nFinal reply:\n" . ($out['reply'] !== '' ? $out['reply'] : '(none)') . "\n\nOK\n";
    exit(0);
}

if ($mode === 'ramp') {
    $targets = [5, 10, 20, 30, 50, 64, 80, 100];
    if (isset($argv[2])) {
        $targets = array_map('intval', explode(',', $argv[2]));
    }

    echo "Ramp mode — targets: " . implode(', ', $targets) . "\n\n";
    $maxOk = 0;

    foreach ($targets as $target) {
        $agent = makeAgent($container);
        echo "--- target={$target} ---\n";
        $out = runProbe($container, $agent, $dispatcher, probePrompt($target));
        $reported = parseReportedCount($out['reply']);
        $ok = $out['parallel'] >= $target;
        if ($ok) {
            $maxOk = $target;
        }

        echo "  API parallel tool_calls: {$out['parallel']}\n";
        echo "  ToolStarted echo count:  " . count($out['ids']) . "\n";
        echo "  n range:                 "
            . ($out['ids'] === [] ? '(none)' : min($out['ids']) . '..' . max($out['ids'])) . "\n";
        echo "  finish reason:           {$out['finish']}\n";
        echo "  model reported:          " . ($reported ?? 'n/a') . "\n";
        if ($out['error'] !== null) {
            echo "  harness error:           {$out['error']}\n";
        }
        echo '  result:                  ' . ($ok ? 'PASS' : 'FAIL') . "\n\n";

        if (!$ok) {
            break;
        }
    }

    echo "=== Ramp summary ===\n";
    echo "Max target passed: {$maxOk}\n\nOK\n";
    exit(0);
}

$target = isset($argv[1]) && is_numeric($argv[1]) ? (int) $argv[1] : 0;
$agent = makeAgent($container);
echo ($target > 0 ? "Fixed target: {$target}\n" : "Open probe: model chooses max parallel\n") . "\n";

$out = runProbe($container, $agent, $dispatcher, probePrompt($target));
$reported = parseReportedCount($out['reply']);

echo "=== Result ===\n";
echo "Max parallel tool_calls in one API response: {$out['parallel']}\n";
echo "Echo ToolStarted (total across steps):       " . count($out['ids']) . "\n";
echo "Distinct n values:                         " . count(array_unique($out['ids'])) . "\n";
if ($out['ids'] !== []) {
    echo "n range:                                   " . min($out['ids']) . '..' . max($out['ids']) . "\n";
}
echo "LLM steps (CompletionResponded):                   {$out['steps']}\n";
echo "Finish reason (peak step):                   {$out['finish']}\n";
echo "Model reported PARALLEL_COUNT:               " . ($reported ?? 'n/a') . "\n";
if ($out['error'] !== null) {
    echo "Harness error:                             {$out['error']}\n";
}
if ($out['sampleArgs'] !== []) {
    echo "Sample tool args (first 3):\n";
    foreach ($out['sampleArgs'] as $i => $args) {
        echo '  [' . ($i + 1) . '] ' . $args . "\n";
    }
}
echo "\nFinal reply:\n" . ($out['reply'] !== '' ? $out['reply'] : '(none)') . "\n\n";

if ($target > 0) {
    echo ($out['parallel'] >= $target ? 'PASS' : 'FAIL') . " (target {$target})\n";
}

echo "\nTip: php live_parallel_echo.php task 1000\n";
echo "     php live_parallel_echo.php ramp\n";
echo "     php live_parallel_echo.php probe\n\nOK\n";
});
