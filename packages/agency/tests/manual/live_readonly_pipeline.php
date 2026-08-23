<?php

declare(strict_types=1);

/**
 * Live plan-only coding lead: same container as coding orchestrator; default task forbids edits.
 *
 *   php packages/agency/tests/manual/live_readonly_pipeline.php
 *   php packages/agency/tests/manual/live_readonly_pipeline.php "custom task…"
 *
 * Resume via agent-chat --thread=ID.
 */

use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

require __DIR__ . '/live_bootstrap.php';

$repoRoot = liveRepoRoot();

$defaultTask = <<<'PROMPT'
We want a visible todo phase trail when using the agent (for humans, not for the model to read back).

Research and write a plan only — do not change code; stop after the plan. Scope: packages/agency.
PROMPT;

$task = $argv[1] ?? $defaultTask;

Runner::run(function (ContainerInterface $container) use ($repoRoot, $task): void {
liveConfigureCodingContainer($repoRoot);
$threadId = liveRunCodingPipeline(
    $container,
    $task,
    bannerExtra: 'Mode: plan-only default task (coding container; prompt forbids edits)',
);

echo "REPL: php packages/agency/bin/agent-chat.php --agent=orchestrator --thread={$threadId}\n";
});
