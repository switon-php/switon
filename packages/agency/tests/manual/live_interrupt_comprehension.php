<?php

declare(strict_types=1);

/**
 * Live probe: does the LLM understand what happened in `[signal:user_abort]` wire?
 *
 * No inbox / signal directives. We only check comprehension — not retry policy.
 *
 *   php packages/agency/tests/manual/live_interrupt_comprehension.php
 *   php packages/agency/tests/manual/live_interrupt_comprehension.php exception
 *   php packages/agency/tests/manual/live_interrupt_comprehension.php resumed
 *   php packages/agency/tests/manual/live_interrupt_comprehension.php cancelled
 *
 * Requires configured LLM (see live_bootstrap.php / ~/.switon/llm.yml).
 */

use Switon\Agency\Agent;
use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Ai\Profile;
use Switon\Ai\TurnStatus;
use Switon\Ai\Signal\UserAbortSignal;
use Switon\Thread\ThreadsInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Envelope;

require __DIR__ . '/live_bootstrap.php';

function liveInterruptBareProfile(): Profile
{
    return new Profile(
        id: 'probe',
        prompt: 'You are a helpful assistant. Follow the user precisely.',
        tools: [],
        skills: [],
        model: 'main',
        maxSteps: 4,
    );
}

function liveInterruptQuizPrompt(): string
{
    return <<<'TXT'
Read the conversation above. There was an echo tool request, then a bracketed status block.

Do you understand what happened? Reply with ONLY one JSON object (no markdown fence):
{"understands_what_happened":true|false,"summary":"one sentence in your own words"}
TXT;
}

/** @return array{understands: bool|null, summary: string, raw: string} */
function liveInterruptParseQuiz(string $assistantText): array
{
    $raw = trim($assistantText);
    $jsonText = $raw;
    if (preg_match('/\{[\s\S]*\}/', $raw, $m) === 1) {
        $jsonText = $m[0];
    }
    $decoded = json_decode($jsonText, true);
    if (!is_array($decoded)) {
        return ['understands' => null, 'summary' => '', 'raw' => $raw];
    }

    $understands = $decoded['understands_what_happened'] ?? null;
    if (is_string($understands)) {
        $understands = match (strtolower(trim($understands))) {
            'true', 'yes', '1' => true,
            'false', 'no', '0' => false,
            default => null,
        };
    }

    return [
        'understands' => is_bool($understands) ? $understands : null,
        'summary' => is_string($decoded['summary'] ?? null) ? trim($decoded['summary']) : '',
        'raw' => $raw,
    ];
}

/** @param array{understands: bool|null, summary: string, raw: string} $parsed */
function liveInterruptGradeException(array $parsed): bool
{
    if ($parsed['understands'] !== true) {
        return false;
    }

    $blob = strtolower($parsed['summary'] . ' ' . $parsed['raw']);

    return str_contains($blob, 'exception')
        || str_contains($blob, 'runtime')
        || str_contains($blob, 'error')
        || str_contains($blob, 'fail')
        || str_contains($blob, 'abort')
        || str_contains($blob, 'reset');
}

/** @param array{understands: bool|null, summary: string, raw: string} $parsed */
function liveInterruptGradeResumed(array $parsed): bool
{
    if ($parsed['understands'] !== true) {
        return false;
    }

    $blob = strtolower($parsed['summary'] . ' ' . $parsed['raw']);

    return str_contains($blob, 'restor')
        || str_contains($blob, 'resum')
        || str_contains($blob, 'session')
        || str_contains($blob, 'open')
        || str_contains($blob, 'incomplete')
        || str_contains($blob, 'sub-agent')
        || str_contains($blob, 'subagent');
}

function liveInterruptSeedEchoNarrativeTurn(
    ThreadsInterface $threads,
    \Switon\Ai\Thread $thread,
    string $turnId = 'turn-probe',
    string $runId = 'run-probe',
): void {
    $thread->turns[] = \Switon\Ai\Turn::of($turnId, status: TurnStatus::Running, model: 'main');
    $completion = Completion::of(UserMessage::of('Call the echo tool with text set to hello.'), new AssistantMessage('Invoking the echo tool with text hello.'));
    $completion->completeId = $runId;
    $completion->step = 1;
    $completion->landedAt = microtime(true);
    $thread->lastTurn()->completions[] = $completion;
    $threads->saveTurn($thread);
}

function liveInterruptExceptionBody(\Switon\Di\Container $container): string
{
    $agent = $container->get(RecordingAgentManager::class)->mint([
        'profile' => liveInterruptBareProfile(),
    ]);
    $error = new \RuntimeException('connection reset by peer');

    return \Switon\Agency\Exception\RunFailedException::format($error);
}

function liveInterruptResumedBody(): string
{
    return "Session restored from persisted state.\n"
        . "Open tool calls without a recorded result: echo (call_id=call_echo_1).\n"
        . "Sub-agent processes that were registered are no longer running.";
}

function liveInterruptCancelledBody(): string
{
    return 'The run was cancelled.';
}

/** @param array{understands: bool|null, summary: string, raw: string} $parsed */
function liveInterruptGradeCancelled(array $parsed): bool
{
    if ($parsed['understands'] !== true) {
        return false;
    }

    $blob = strtolower($parsed['summary'] . ' ' . $parsed['raw']);

    return str_contains($blob, 'stop')
        || str_contains($blob, 'cancel')
        || str_contains($blob, 'user')
        || str_contains($blob, 'abort');
}

/**
 * Seed one abort / resume row using the class that now owns that kind:
 * `cancelled` → {@see \Switon\Ai\Signal\UserAbortSignal}, `exception` → {@see \Switon\Ai\Signal\AssistantAbortSignal},
 * `resumed` → {@see \Switon\Ai\Signal\SessionResumedSignal}.
 */
function liveInterruptAppendSignalRow(
    ThreadsInterface $threads,
    \Switon\Ai\Thread $thread,
    string $kind,
    string $body,
    string $turnId = 'turn-probe',
    string $runId = 'run-probe',
): void {
    $thread->turns[] = \Switon\Ai\Turn::of($turnId, status: TurnStatus::Running, model: 'main');
    $completion = Completion::of(liveInterruptSignalFor($kind, $body));
    $completion->completeId = 'abort-' . $kind;
    $completion->step = 1;
    $completion->landedAt = microtime(true);
    $thread->lastTurn()->completions[] = $completion;
    $threads->saveTurn($thread);
}

/** Signal instance carrying `$kind` on the side that produces it. */
function liveInterruptSignalFor(string $kind, string $body): \Switon\Ai\Message
{
    return match ($kind) {
        'exception' => new \Switon\Ai\Signal\AssistantAbortSignal(
            \Switon\Ai\Signal\AssistantAbortSignal::KIND_EXCEPTION,
            body: $body,
        ),
        'resumed' => new \Switon\Ai\Signal\SessionResumedSignal(body: $body),
        default => new UserAbortSignal(body: $body),
    };
}

function liveInterruptLastAssistantText(Agent $agent): string
{
    $text = '';
    foreach ($agent->thread()->turns as $_turn) {
        foreach ($_turn->completions as $row) {
            $chunk = trim((string) ($row->output?->content ?? ''));
            if ($chunk !== '') {
                $text = $chunk;
            }
        }
    }

    return $text;
}

function liveInterruptEchoSystemPrompt(Agent $agent): string
{
    $charters = (new \ReflectionProperty(Agent::class, 'charters'))->getValue($agent);
    $charter = $charters->get($agent);
    return $charter->prompt;
}

/** @param callable(array{understands: bool|null, summary: string, raw: string}): bool $grade */
function probeInterruptComprehension(
    \Switon\Di\Container $container,
    string $label,
    string $kind,
    string $body,
    callable $grade,
): bool {
    echo "\n=== {$label} (no directives, injected wire) ===\n";

    $agent = $container->get(RecordingAgentManager::class)->mint([
        'profile' => liveInterruptBareProfile(),
    ]);
    liveTrackOpenAgent($agent);

    $system = liveInterruptEchoSystemPrompt($agent);
    echo 'system has inbox directive: ' . (str_contains($system, '## Inbox') ? 'yes (unexpected)' : 'no') . "\n";

    $threads = $container->get(ThreadsInterface::class);
    $thread = $agent->thread();
    liveInterruptSeedEchoNarrativeTurn($threads, $thread);
    liveInterruptAppendSignalRow($threads, $thread, $kind, $body);

    $wire = liveInterruptSignalFor($kind, $body)->wire()[0]['text'];
    echo "seeded wire:\n{$wire}\n";

    liveRunInCoroutine(static function () use ($agent): void {
        $agent->run(Envelope::of(liveInterruptQuizPrompt(), liveModel($container, $agent->profile()->model)));
    });

    $parsed = liveInterruptParseQuiz(liveInterruptLastAssistantText($agent));
    echo 'understands_what_happened: ' . json_encode($parsed['understands']) . "\n";
    echo 'summary: ' . $parsed['summary'] . "\n";
    if ($parsed['raw'] !== '' && $parsed['summary'] === '') {
        echo 'model raw: ' . substr($parsed['raw'], 0, 400) . "\n";
    }

    $ok = $grade($parsed);
    echo 'RESULT: ' . ($ok ? 'PASS' : 'FAIL') . "\n";

    return $ok;
}

\Switon\Cli\Runner::run(function (\Switon\Di\ContainerInterface $container) use ($argv): void {
try {
    liveRegisterStubProfiles($container);
    liveRegisterAgentPackage($container);
    liveStartConsole($container);
} catch (\Throwable $e) {
    fwrite(STDERR, 'LLM bootstrap failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$only = $argv[1] ?? null;
$probes = [
    'exception' => static fn (): bool => probeInterruptComprehension(
        $container,
        'exception',
        'exception',
        liveInterruptExceptionBody($container),
        liveInterruptGradeException(...),
    ),
    'resumed' => static fn (): bool => probeInterruptComprehension(
        $container,
        'resumed',
        'resumed',
        liveInterruptResumedBody(),
        liveInterruptGradeResumed(...),
    ),
    'cancelled' => static fn (): bool => probeInterruptComprehension(
        $container,
        'cancelled',
        'cancelled',
        liveInterruptCancelledBody(),
        liveInterruptGradeCancelled(...),
    ),
];
if ($only !== null && !isset($probes[$only])) {
    fwrite(STDERR, 'Unknown probe. Choose: ' . implode(', ', array_keys($probes)) . "\n");
    exit(2);
}

$run = $only !== null ? [$only => $probes[$only]] : $probes;
$passed = 0;
$failed = 0;
foreach ($run as $fn) {
    if ($fn()) {
        $passed++;
    } else {
        $failed++;
    }
}

echo "\n--- summary ---\n";
echo "pass: {$passed}, fail: {$failed}\n";
exit($failed > 0 ? 1 : 0);
});
