<?php

declare(strict_types=1);

/**
 * Live demo: deep delegate nesting in AgentConsole (real LLM).
 *
 *   php packages/agency/tests/manual/live_nesting_depth.php              # sync, timer leaf depth 5
 *   php packages/agency/tests/manual/live_nesting_depth.php 3            # former grandchild demo (depth 3)
 *   php packages/agency/tests/manual/live_nesting_depth.php 7            # sync, timer leaf depth 7
 *   php packages/agency/tests/manual/live_nesting_depth.php 7 async      # host task_deliver inbox
 *   php packages/agency/tests/manual/live_nesting_depth.php 7 async-full # nested create/assign + leaf task (stress)
 *
 * Timer runs at depth N (agent_timer leaf from relay at N−1). Console indent = depth × 4 spaces.
 */

use Switon\Ai\Message\UserMessage;
use Switon\Agency\Agent;
use Switon\Ai\Envelope;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

require __DIR__ . '/live_bootstrap.php';

$waitMode = liveNestingWaitMode($argv);
$timerAtDepth = liveNestingDepthFromArgv($argv, 5);
$firstRemaining = liveNestingFirstRemainingDepth($timerAtDepth);
$delegateMessage = liveNestingDelegateMessage($timerAtDepth);

Runner::run(function (ContainerInterface $container) use ($waitMode, $timerAtDepth, $firstRemaining, $delegateMessage): void {
liveApplyNestingProfileBindings($container);

/** @var Agent $host */
$host = liveOpenAgent($container, Agent::class);
$profile = clone $host->profile();
$profile->prompt .= liveNestingDepthPromptAddendum($waitMode);
$profile->maxSteps = 6 + $timerAtDepth * 3;
liveBindProfileOverride($host, $profile, $container);

echo 'Deep nesting demo (timer leaf target depth ' . $timerAtDepth . ', ' . liveNestingWaitModeLabel($waitMode) . ")\n";
echo "Host delegate remaining_depth: {$firstRemaining}\n";
echo 'Expected date tool indent: ' . ($timerAtDepth * 4) . " spaces\n";
echo "thread={$host->threadId()}\n";
liveNestingAsyncFullWarning($waitMode);

$prompt = liveNestingHostUserPrompt($delegateMessage, $waitMode, 'relay');

echo "Running agent (LLM + tools)…\n\n";

liveRunInCoroutine(static function () use ($host, $prompt): void {
    $host->deliver(Envelope::of($prompt, liveModel($container, $host->profile()->model)));
    liveDrainDelegations($host);
});

echo "\nOK host depth={$host->depth()}\n";
});
