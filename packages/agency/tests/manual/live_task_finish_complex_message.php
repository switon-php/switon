<?php

declare(strict_types=1);

/**
 * Live stress (CC / MiniMax Anthropic-compatible): worker must put a complex PHP
 * snippet into `task_finish.body` (quotes, braces, newlines, backslashes).
 * Checks whether tool JSON survives and the parent signal body is intact.
 *
 *   php packages/agency/tests/manual/live_task_finish_complex_message.php
 *   php packages/agency/tests/manual/live_task_finish_complex_message.php 300
 */

use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Tooling\Event\ToolFailed;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Llm\Event\CompletionRequesting;
use Switon\Ai\Exception\InvalidToolArgumentsException;
use Switon\Ai\Message\UserMessage;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Thread\ThreadsInterface;

require __DIR__ . '/live_bootstrap.php';

/** Must appear verbatim in task_finish.body (and thus parent signal body). ~4KB+. */
$snippet = <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo\Stress;

use Switon\Thread\Threads;
use Switon\Core\Strings;
use JsonException;
use RuntimeException;
use Switon\Agency\Agent;
use Switon\Ai\Envelope;
use Switon\Ai\Thread;

/**
 * Live-test payload: quotes, braces, regex, heredoc, Windows paths, nested JSON.
 * END_MARKER_ALPHA_9f3c
 */
class PayloadBuilder
{
    protected const SCHEMA = '{"type":"object","required":["id","tags"]}';

    /** @param array<string, mixed> $opts */
    public function build(string $name, array $opts = []): string
    {
        $q = "He said: \"hello {$name}\"";
        $path = "C:\\Users\\demo\\AppData\\Local\\Temp\\x.php";
        $re = '/^file:\/\/\/([A-Za-z]:)?\\\\.+/';
        $json = '{"ok":true,"n":1,"path":"C:\\\\temp\\\\x.php","q":"say \\"hi\\""}';

        $rows = [];
        for ($i = 0; $i < 24; $i++) {
            $rows[] = [
                'i' => $i,
                'label' => "row-{$i}: {$q}",
                'meta' => [
                    'win' => $path,
                    're' => $re,
                    'schema' => self::SCHEMA,
                    'blob' => $json,
                    'token' => sprintf('MID_TOKEN_%02d_KEEP', $i),
                ],
            ];
        }

        $sql = <<<'SQL'
SELECT id, name, payload
FROM events
WHERE name = 'He said: "hello"'
  AND path LIKE 'C:\temp\%'
  AND json_extract(payload, '$.ok') = true;
SQL;

        $yamlish = <<<'YML'
rules:
  - id: "escape-demo"
    when: 'path == "C:\\temp\\x.php"'
    then: 'emit("He said: \"hello\"")'
YML;

        try {
            $body = match ($name) {
                'a' => $json,
                'sql' => $sql,
                'yml' => $yamlish,
                default => "{$q}\n\tpath={$path}\n" . json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            };
        } catch (JsonException $e) {
            throw new RuntimeException('json failed: ' . $e->getMessage(), 0, $e);
        }

        if ($body === '') {
            throw new RuntimeException("empty body for \"{$name}\"");
        }

        return $body;
    }

    /** @return list<string> */
    public function catalog(): array
    {
        return [
            'alpha' => 'END_MARKER_ALPHA_9f3c',
            'mid' => 'MID_TOKEN_00_KEEP',
            'omega' => 'END_MARKER_OMEGA_7b2e',
            'note' => "nested \"quotes\" and {braces} plus \$vars",
            'win' => "C:\\bin\\php.exe --define foo=\"bar\\\"baz\"",
            'regex' => '/(?P<label>"He said:")\\s+(?P<name>[\\w\\\\]+)/',
        ];
    }

    public static function fingerprint(): string
    {
        return 'END_MARKER_OMEGA_7b2e';
    }
}

class PayloadRenderer
{
    public function render(PayloadBuilder $builder, string $name): string
    {
        $head = "<!-- BEGIN_RENDER name=\"{$name}\" -->\n";
        $tail = "\n<!-- END_RENDER fingerprint=\"" . PayloadBuilder::fingerprint() . "\" -->\n";

        return $head . $builder->build($name) . $tail;
    }
}

function greet(string $name): string
{
    return (new PayloadBuilder())->build($name, [
        'note' => "nested \"quotes\" and {braces}",
        'escape' => "line1\\nline2\\tC:\\\\bin\\\\php.exe",
    ]);
}

function dump_catalog(): string
{
    $lines = [];
    foreach ((new PayloadBuilder())->catalog() as $k => $v) {
        $lines[] = "{$k}=" . json_encode($v, JSON_UNESCAPED_UNICODE);
    }

    return implode("\n", $lines);
}

// filler so tool-arg JSON is long enough to stress weak encoders
$FILLER = [
    'block_01' => 'AAA_KEEP_BLOCK_01: path="C:\\temp\\a.php" quote="say \\"one\\""',
    'block_02' => 'AAA_KEEP_BLOCK_02: path="C:\\temp\\b.php" quote="say \\"two\\""',
    'block_03' => 'AAA_KEEP_BLOCK_03: path="C:\\temp\\c.php" quote="say \\"three\\""',
    'block_04' => 'AAA_KEEP_BLOCK_04: path="C:\\temp\\d.php" quote="say \\"four\\""',
    'block_05' => 'AAA_KEEP_BLOCK_05: path="C:\\temp\\e.php" quote="say \\"five\\""',
    'block_06' => 'AAA_KEEP_BLOCK_06: path="C:\\temp\\f.php" quote="say \\"six\\""',
    'block_07' => 'AAA_KEEP_BLOCK_07: path="C:\\temp\\g.php" quote="say \\"seven\\""',
    'block_08' => 'AAA_KEEP_BLOCK_08: path="C:\\temp\\h.php" quote="say \\"eight\\""',
];

// END_MARKER_OMEGA_7b2e
PHP;

Runner::run(function (ContainerInterface $container) use ($FILLER): void {
liveBindTools($container, []);
liveRegisterAgentPackage($container);
liveStartConsole($container);
$events = [];
$container->get(ListenerProviderInterface::class)->on(null, static function (object $event) use (&$events): void {
    $events[] = $event;
});
$eventsOf = static function (string $class) use (&$events): array {
    return array_values(array_filter($events, static fn (object $event): bool => $event instanceof $class));
};

$taskPrompt = trim($container->get(\Switon\Agency\SegmentsInterface::class)->get('task')->prompt);
$host = $container->get(RecordingAgentManager::class)->mint([
    'profile' => new \Switon\Ai\Profile(
        id: 'task-finish-stress',
        prompt: <<<'PROMPT'
You are a thin host for a tool-argument stress test.
- Do not invent code yourself beyond dispatching.
- Call task_deliver once, then wait for inbox task_finish and reply with exactly: HOST_OK
- Do not narrate before the inbox event.
PROMPT
            . "\n\n"
            . $taskPrompt,
        tools: ['task_deliver'],
        skills: [],
        model: 'main',
        maxSteps: 16,
    ),
]);

$workerBrief = <<<PROMPT
You are a one-shot worker. Do not call other tools.

Call task_finish exactly once with:
- status=completed
- message = the ENTIRE PHP snippet below, character-for-character (keep newlines, quotes, backslashes)

Do not wrap the snippet in markdown fences. Do not summarize. The message field must be only this source:

{$snippet}
PROMPT;

echo "=== live_task_finish_complex_message (MiniMax / CC) ===\n";
echo "thread={$host->threadId()}\n";
echo "snippet bytes=" . strlen($snippet) . "\n\n";

$runError = null;
liveRunInCoroutine(static function () use ($host, $workerBrief, &$runError): void {
    try {
        $host->run(Envelope::of("Dispatch one task_deliver:\n"
            . "- name=\"coder\"\n"
            . "- blueprint=\"assist\"\n"
            . "- message must be exactly the worker brief below (copy verbatim).\n\n"
            . "===== WORKER BRIEF =====\n"
            . $workerBrief
            . "\n===== END BRIEF =====\n", liveModel($container, $host->profile()->model)));
        liveDrainDelegations($host, 200.0);
    } catch (Throwable $e) {
        $runError = $e;
    } finally {
        liveKillAgentTree($host);
    }
});

$threads = $container->get(ThreadsInterface::class);
$toolCalls = [];
$finishBody = '';
$finishStatus = '';
$invalidArgHits = 0;
$toolFailedHits = 0;
$hostOk = false;
$rawFinishArgLens = [];

foreach ($eventsOf(ToolFailed::class) as $event) {
    $toolFailedHits++;
    $name = $event->call->name;
    $msg = $event->message();
    echo '[ToolFailed] ' . $name . ': ' . substr($msg, 0, 240) . "\n";
}

$scanThread = static function (string $threadId) use (
    $threads,
    &$toolCalls,
    &$finishBody,
    &$finishStatus,
    &$hostOk,
    &$rawFinishArgLens,
    $host,
): void {
    $transcriptThread = Thread::of($threadId, $threadId);
    $threads->load($transcriptThread);
    foreach (liveWireMessages($transcriptThread->completions()) as $message) {
        if ($message instanceof AssistantMessage) {
            foreach ($message->toolCalls as $call) {
                $toolCalls[$call->name] = ($toolCalls[$call->name] ?? 0) + 1;
                if ($call->name === 'task_finish') {
                    $args = $call->arguments;
                    $raw = is_string($args) ? $args : json_encode($args, JSON_UNESCAPED_UNICODE);
                    $rawFinishArgLens[] = strlen((string) $raw);
                    if ($threadId === $host->threadId()) {
                        echo "[host wire] unexpected task_finish on host\n";
                    }
                }
            }
            if ($threadId === $host->threadId() && str_contains((string) $message->content, 'HOST_OK')) {
                $hostOk = true;
            }
        }
        $event = liveTaskEventFromMessage($message);
        if ($event !== null && ($event['signal'] ?? '') === 'task_finish') {
            $finishStatus = (string) ($event['status'] ?? '');
            $finishBody = (string) ($event['body'] ?? '');
        }
    }
};

$scanThread($host->threadId());
foreach (liveAgentChildren($host) as $sub) {
    $scanThread($sub->threadId());
}

foreach ($eventsOf(CompletionRequesting::class) as $req) {
    foreach ($req->thread->completions() as $completion) {
        foreach ([$completion->input, $completion->output] as $message) {
            if ($message === null) {
                continue;
            }
            $text = (string) ($message->content ?? '');
            if (
                str_contains($text, 'arguments invalid')
                || str_contains($text, 'invalid JSON')
                || str_contains($text, 'InvalidToolArguments')
                || str_contains($text, 'Failed to parse')
            ) {
                $invalidArgHits++;
            }
        }
    }
}

// Markers after tool-arg JSON decode (body should be real PHP source).
$critical = [
    'namespace Demo\\Stress' => str_contains($finishBody, 'namespace Demo\\Stress'),
    'END_MARKER_ALPHA_9f3c' => str_contains($finishBody, 'END_MARKER_ALPHA_9f3c'),
    'END_MARKER_OMEGA_7b2e' => str_contains($finishBody, 'END_MARKER_OMEGA_7b2e'),
    'MID_TOKEN_%02d_KEEP' => str_contains($finishBody, 'MID_TOKEN_%02d_KEEP'),
    'AAA_KEEP_BLOCK_08' => str_contains($finishBody, 'AAA_KEEP_BLOCK_08'),
    'He said:' => str_contains($finishBody, 'He said:'),
    'PayloadRenderer' => str_contains($finishBody, 'PayloadRenderer'),
    '<<<\'SQL\'' => str_contains($finishBody, "<<<'SQL'"),
    '<<<\'YML\'' => str_contains($finishBody, "<<<'YML'"),
    'match ($name)' => str_contains($finishBody, 'match ($name)'),
    // nowdoc source contains: say \\"hi\\"  (two backslashes before each quote)
    'say \\\\ "hi"' => str_contains($finishBody, 'say \\\\"hi\\\\"'),
    // fragile escape line — MiniMax often drops a backslash here
    'regex \\\\s keep' => str_contains($finishBody, '\\\\s+(?P<name>[\\\\w\\\\\\\\]+)'),
];
$pathOk = str_contains($finishBody, 'C:\\Users\\demo\\AppData')
    || str_contains($finishBody, 'C:\\\\Users\\\\demo\\\\AppData')
    || str_contains($finishBody, 'C:/Users/demo/AppData');
$critical['path C:\\Users\\demo'] = $pathOk;

$expectedLen = strlen($snippet);
$gotLen = strlen($finishBody);
$lenRatio = $expectedLen > 0 ? $gotLen / $expectedLen : 0.0;
$critical['length >= 90%'] = $lenRatio >= 0.90;
$exactMatch = $finishBody === $snippet;
$critical['exact byte match'] = $exactMatch;

$allCritical = !in_array(false, $critical, true);
$sawTaskDeliver = ($toolCalls['task_deliver'] ?? 0) > 0;
$completed = $finishStatus === 'completed' && $finishBody !== '';

echo "\n--- summary ---\n";
if ($runError !== null) {
    echo 'run error: ' . $runError::class . ': ' . $runError->getMessage() . "\n";
    if ($runError instanceof InvalidToolArgumentsException) {
        echo "  (InvalidToolArguments — tool JSON likely broke)\n";
    }
}
echo 'tools: ' . json_encode($toolCalls, JSON_UNESCAPED_UNICODE) . "\n";
echo 'task_deliver: ' . ($sawTaskDeliver ? 'YES' : 'no') . "\n";
echo 'task_finish status: ' . ($finishStatus !== '' ? $finishStatus : '(missing)') . "\n";
echo 'snippet bytes: ' . strlen($snippet) . "\n";
echo 'task_finish body bytes: ' . strlen($finishBody)
    . ' (ratio=' . number_format($lenRatio, 3) . ")\n";
echo 'task_finish raw arg lens: ' . json_encode($rawFinishArgLens) . "\n";
echo 'invalid-arg nudges seen: ' . $invalidArgHits . "\n";
echo 'ToolFailed events: ' . $toolFailedHits . "\n";
echo 'HOST_OK: ' . ($hostOk ? 'YES' : 'no') . "\n";
echo "critical markers:\n";
foreach ($critical as $label => $ok) {
    echo '  ' . ($ok ? '[OK]  ' : '[MISS]') . ' ' . $label . "\n";
}
if ($finishBody !== '') {
    echo "\n--- finish body (first 500 chars) ---\n";
    echo substr($finishBody, 0, 500) . (strlen($finishBody) > 500 ? "\n…" : '') . "\n";
}

if ($finishBody !== '' && !$exactMatch) {
    echo "\n--- first diff (context) ---\n";
    $max = min(strlen($snippet), strlen($finishBody));
    $i = 0;
    while ($i < $max && $snippet[$i] === $finishBody[$i]) {
        $i++;
    }
    $from = max(0, $i - 40);
    echo 'at offset ' . $i . ":\n";
    echo ' expect: ' . substr($snippet, $from, 80) . "\n";
    echo ' got:    ' . substr($finishBody, $from, 80) . "\n";
}

$ok = $sawTaskDeliver && $completed && $allCritical;
echo "\nRESULT: " . ($ok ? 'PASS' : 'FAIL') . "\n";
if (!$ok && $completed && !$exactMatch) {
    echo "HINT: finish arrived but code payload was mangled/truncated — typical weak-model tool JSON / copy drift.\n";
}
if (!$ok && $invalidArgHits > 0) {
    echo "HINT: saw invalid-argument retries — model struggled to encode message as tool JSON.\n";
}

exit($ok ? 0 : 1);
});
