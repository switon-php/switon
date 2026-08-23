<?php

declare(strict_types=1);

/**
 * Live probe: sub-agent puts the complete deliverable in task_finish (driven by task-deliver.md only).
 *
 * Delegate message is minimal: goal / scope / constraints only — no Expected output format,
 * no task_finish hints. Format comes from profile + task-deliver.md delivery rule.
 *
 *   php packages/agency/tests/manual/live_task_finish_deliverable_probe.php ephemeral
 *   php packages/agency/tests/manual/live_task_finish_deliverable_probe.php persistent
 *   php packages/agency/tests/manual/live_task_finish_deliverable_probe.php both
 */

use Switon\Ai\Completion;
use Switon\Ai\Envelope;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Cli\Runner;
use Switon\Core\FilesystemInterface;
use Switon\Di\ContainerInterface;

require __DIR__ . '/live_bootstrap.php';

$mode = $argv[1] ?? 'both';
if (!in_array($mode, ['ephemeral', 'persistent', 'both'], true)) {
    fwrite(STDERR, "Usage: php live_task_finish_deliverable_probe.php [ephemeral|persistent|both]\n");
    exit(2);
}

$repoRoot = livePromptRoot();
$delegateMessage = <<<MSG
Goal: For each listed package, read composer.json and report its one-line description field.

Scope (read only):
- {$repoRoot}/packages/agency
- {$repoRoot}/packages/llm
- {$repoRoot}/packages/router

Constraints: Read-only; no edits, shell, or delegation.
MSG;

/**
 * @return array{host_finish: string, child_finish_tool: string, child_assistant_max: int}
 */
function liveCollectFinishArtifacts(FilesystemInterface $fs, string $hostDatadir): array
{
    $hostFinish = '';
    foreach (liveTranscriptJsonlTurns($fs, $hostDatadir) as $turn) {
        foreach ($turn->completions as $completion) {
            $input = $completion->input;
            if ($input instanceof \Switon\Agency\Thread\TaskFinishSignal
                && ($input->fields['status'] ?? '') === 'completed'
            ) {
                $hostFinish = $input->body;
            }
        }
    }

    $childFinishTool = '';
    $childAssistantMax = 0;
    $subRoot = rtrim($hostDatadir, '/') . '/subagents';
    if (is_dir($subRoot)) {
        foreach (scandir($subRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $childDatadir = $subRoot . '/' . $entry;
            if (!is_dir($childDatadir)) {
                continue;
            }
            foreach (liveTranscriptJsonlTurns($fs, $childDatadir) as $turn) {
                foreach ($turn->completions as $completion) {
                    $output = $completion->output;
                    if ($output instanceof AssistantMessage) {
                        $len = strlen(trim((string) ($output->content ?? '')));
                        if ($len > $childAssistantMax) {
                            $childAssistantMax = $len;
                        }
                        foreach ($output->toolCalls as $call) {
                            if ($call->name !== 'task_finish') {
                                continue;
                            }
                            $message = $call->arguments['message'] ?? '';
                            if (is_string($message) && strlen(trim($message)) > strlen($childFinishTool)) {
                                $childFinishTool = trim($message);
                            }
                        }
                    }
                }
            }
        }
    }

    return [
        'host_finish' => $hostFinish,
        'child_finish_tool' => $childFinishTool,
        'child_assistant_max' => $childAssistantMax,
    ];
}

/**
 * Distinctive composer.json description fragments — proves finish is not name-only summary.
 *
 * @var array<string, list<string>>
 */
const LIVE_PROBE_DESCRIPTION_MARKERS = [
    'agent' => ['tools and MCP', 'agent loop'],
    'llm' => ['swappable wire', 'chat client'],
    'router' => ['Attribute-discovered', 'route tables', 'path matching'],
];

/**
 * @return array{ok: bool, reasons: list<string>, artifacts: array<string, mixed>}
 */
function liveScoreDeliverable(array $artifacts): array
{
    $body = $artifacts['child_finish_tool'] !== ''
        ? $artifacts['child_finish_tool']
        : $artifacts['host_finish'];
    $reasons = [];
    $len = strlen($body);
    if ($len < 80) {
        $reasons[] = "finish body too short ({$len} chars)";
    }
    foreach (['agent', 'llm', 'router'] as $pkg) {
        if (!preg_match('/\b' . preg_quote($pkg, '/') . '\b/i', $body)) {
            $reasons[] = "missing package name: {$pkg}";
        }
    }
    $markerHits = 0;
    foreach (LIVE_PROBE_DESCRIPTION_MARKERS as $pkg => $markers) {
        foreach ($markers as $marker) {
            if (stripos($body, $marker) !== false) {
                $markerHits++;
                break;
            }
        }
    }
    if ($markerHits < 2) {
        $reasons[] = "too few composer description fragments in finish ({$markerHits}/3 packages)";
    }
    if (
        $artifacts['child_assistant_max'] > 0
        && $len > 0
        && $artifacts['child_assistant_max'] > $len * 1.5
    ) {
        $reasons[] = 'child assistant prose longer than task_finish.body (possible summary-only finish)';
    }

    return [
        'ok' => $reasons === [],
        'reasons' => $reasons,
        'artifacts' => $artifacts + ['scored_body' => $body, 'scored_len' => $len, 'marker_hits' => $markerHits],
    ];
}

/**
 * @return array{ok: bool, label: string, score: array<string, mixed>}
 */
function liveRunEphemeral(): array
{
    $container = $GLOBALS['container'];
    $delegateMessage = $GLOBALS['delegateMessage'];

    $lead = liveOpenOrchestrator($container);
    echo "\n=== EPHEMERAL (task_deliver + coding.explore) ===\n";
    echo "host thread={$lead->threadId()}\n";

    $prompt = <<<PROMPT
Delegate this read-only task ONCE via task_deliver (do not use file tools yourself):

- name: "pkg3"
- agent: "coding.explore"
- message: (copy verbatim below)

---
{$delegateMessage}
---

Wait for inbox task_finish. Reply with one line: "done".
PROMPT;

    liveRunInCoroutine(static function () use ($lead, $prompt): void {
        $lead->run(Envelope::of($prompt, liveModel($container, $lead->profile()->model)));
        liveDrainDelegations($lead, timeoutSeconds: 180.0, quietSeconds: 5.0);
    });

    $fs = $container->get(FilesystemInterface::class);
    $artifacts = liveCollectFinishArtifacts($fs, $lead->thread()->datadir);
    $score = liveScoreDeliverable($artifacts);
    livePrintScore('ephemeral', $score);

    return ['ok' => $score['ok'], 'label' => 'ephemeral', 'score' => $score];
}

/**
 * @return array{ok: bool, label: string, score: array<string, mixed>}
 */
function liveRunPersistent(): array
{
    $container = $GLOBALS['container'];
    $delegateMessage = $GLOBALS['delegateMessage'];

    $lead = liveOpenOrchestrator($container);
    echo "\n=== PERSISTENT (task_deliver + coding.explore) ===\n";
    echo "host thread={$lead->threadId()}\n";

    $prompt = <<<PROMPT
Delegate this read-only task to a sub-agent (do not use file tools yourself):

1. task_deliver name="pkg3" agent="coding.explore" with this message (verbatim):

---
{$delegateMessage}
---

Wait for inbox task_finish from pkg3. Reply with one line: "done".
PROMPT;

    liveRunInCoroutine(static function () use ($lead, $prompt): void {
        $lead->run(Envelope::of($prompt, liveModel($container, $lead->profile()->model)));
        liveDrainDelegations($lead, timeoutSeconds: 180.0, quietSeconds: 5.0);
    });

    $fs = $container->get(FilesystemInterface::class);
    $artifacts = liveCollectFinishArtifacts($fs, $lead->thread()->datadir);
    $score = liveScoreDeliverable($artifacts);
    livePrintScore('persistent', $score);

    return ['ok' => $score['ok'], 'label' => 'persistent', 'score' => $score];
}

/** @param array{ok: bool, reasons: list<string>, artifacts: array<string, mixed>} $score */
function livePrintScore(string $label, array $score): void
{
    $body = (string) ($score['artifacts']['scored_body'] ?? '');
    $len = (int) ($score['artifacts']['scored_len'] ?? 0);
    echo "--- {$label} ---\n";
    echo "host signal finish len: " . strlen((string) ($score['artifacts']['host_finish'] ?? '')) . "\n";
    echo "child task_finish tool len: " . strlen((string) ($score['artifacts']['child_finish_tool'] ?? '')) . "\n";
    echo "child max assistant prose len: " . (int) ($score['artifacts']['child_assistant_max'] ?? 0) . "\n";
    echo "description marker hits: " . (int) ($score['artifacts']['marker_hits'] ?? 0) . "/3\n";
    echo "scored body len: {$len}\n";
    echo "preview:\n" . substr($body, 0, 600) . ($len > 600 ? "\n…" : '') . "\n";
    if ($score['reasons'] !== []) {
        echo "issues: " . implode('; ', $score['reasons']) . "\n";
    }
    echo 'RESULT: ' . ($score['ok'] ? 'PASS' : 'FAIL') . "\n";
}

Runner::run(function (ContainerInterface $container) use ($repoRoot, $mode, $delegateMessage): void {
    liveConfigureCodingContainer($repoRoot);
    $GLOBALS['container'] = $container;
    $GLOBALS['delegateMessage'] = $delegateMessage;

    $results = [];
    if ($mode === 'ephemeral' || $mode === 'both') {
        $results[] = liveRunEphemeral();
    }
    if ($mode === 'persistent' || $mode === 'both') {
        $results[] = liveRunPersistent();
    }

    $allOk = true;
    foreach ($results as $result) {
        $allOk = $allOk && $result['ok'];
    }

    echo "\nOVERALL: " . ($allOk ? 'PASS' : 'FAIL') . "\n";
    exit($allOk ? 0 : 1);
});
