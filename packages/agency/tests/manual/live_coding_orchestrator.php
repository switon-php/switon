<?php

declare(strict_types=1);

/**
 * Live coding orchestrator: always mints a thread; task from argv or default.
 *
 *   php packages/agency/tests/manual/live_coding_orchestrator.php
 *   php packages/agency/tests/manual/live_coding_orchestrator.php "custom task…"
 *   php packages/agency/tests/manual/live_coding_orchestrator.php --langfuse
 *   php packages/agency/tests/manual/live_coding_orchestrator.php --langfuse "custom task…"
 *
 * Resume an existing thread via agent-chat --thread=ID (not this script).
 */

use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

require __DIR__ . '/live_bootstrap.php';

$repoRoot = liveRepoRoot();

$defaultCodingTask = <<<'PROMPT'
GlobTool already has GlobToolTest — verify coverage and run it.

Run the coding pipeline:
1. coding.explore — locate GlobTool and GlobToolTest under packages/tooling
2. coding.implement — only if a meaningful gap remains; otherwise skip edits
3. coding.investigate — from repo root run bash tests/run.sh tooling --filter GlobToolTest

Constraints: packages/tooling/ only; no commit; no README.
Report: files changed (if any), test command, and results.
PROMPT;

$defaultLangfuseTask = <<<'PROMPT'
Langfuse integration smoke via coding pipeline (keep scope small):

1. coding.explore — read `packages/langfuse/tests/manual/smoke_export.php` and list what it does
2. coding.implement — add `packages/langfuse/tests/manual/hello_export.php`: CLI script, prints exactly one line `Hello Langfuse from agent` followed by today's UTC date (Y-m-d)
3. coding.investigate — run `php packages/langfuse/tests/manual/hello_export.php` and quote stdout

Constraints: only under `packages/langfuse/`; no commit; no README edits.
Report: files touched, command output, and whether hello script works.
PROMPT;

$langfuse = false;
$taskParts = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--langfuse') {
        $langfuse = true;
        continue;
    }
    $taskParts[] = $arg;
}
$customTask = $taskParts === [] ? null : implode(' ', $taskParts);

Runner::run(function (ContainerInterface $container) use ($repoRoot, $langfuse, $customTask, $defaultLangfuseTask, $defaultCodingTask): void {
if ($langfuse) {
    liveConfigureCodingContainer($repoRoot);
    if (!liveBindLangfuse($container)) {
        fwrite(STDERR, "Set LANGFUSE_BASE_URL, LANGFUSE_PUBLIC_KEY, LANGFUSE_SECRET_KEY\n");
        exit(1);
    }
    $task = $customTask ?? $defaultLangfuseTask;
    $threadId = liveRunCodingPipeline(
        $container,
        $task,
        drainTimeoutSeconds: 600.0,
        quietSeconds: 8.0,
        bannerExtra: 'Langfuse: Sessions tab → search the thread id above (host thread; includes sub-agents)',
    );
    echo 'Langfuse UI: ' . getenv('LANGFUSE_BASE_URL') . " → Sessions → {$threadId}\n";
} else {
    liveConfigureCodingContainer($repoRoot);
    liveRunCodingPipeline($container, $customTask ?? $defaultCodingTask);
}
});
