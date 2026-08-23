<?php

declare(strict_types=1);

/**
 * Live check: two task_deliver runs on the same child share session history.
 *
 *   php packages/agency/tests/manual/live_multi_run.php
 */

use Switon\Thread\ThreadsInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Agency\Agent;
use Switon\Ai\Envelope;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;
use Switon\Ai\Thread;

require __DIR__ . '/live_bootstrap.php';

Runner::run(function (ContainerInterface $container): void {
liveConfigureOrchestratorContainer($container);
/** @var Agent $agent */
$agent = liveOpenOrchestrator($container);

$code = 'XYZZY-' . random_int(1000, 9999);

$prompt = <<<TXT
Tools only — no plain-text plans.

Run A (this user message):
1. Exactly one task_deliver name="dialog" agent="text" message="Remember this code exactly: {$code}. task_finish to parent with status=completed and only ACK."
2. STOP. Do not call more tools until a later inbox run.

Later inbox run (when task_finish ACK arrives as user/runtime signal):
3. Exactly one task_deliver name="dialog" agent="text" message="What code did I ask you to remember? task_finish to parent with status=completed and only the code."
4. STOP again until the recall task_finish arrives.

Final inbox/user run after recall finishes: say whether the recalled body equals {$code}.
TXT;

echo "=== multi-run task_deliver memory ===\n";
echo "secret code: {$code}\n\n";

liveRunInCoroutine(static function () use ($agent, $prompt): void {
    $agent->run(Envelope::of($prompt, liveModel($container, $agent->profile()->model)));
    liveDrainDelegations($agent, 90.0, 4.0);
});

/** Tolerate legacy `[n]` / `[n.m]` tool label before JSON decode. */
$unstamp = static function (string $content): string {
    if (preg_match('/^\[\d+(?:\.\d+)?\]\s+/', $content, $m) === 1) {
        return substr($content, strlen($m[0]));
    }

    return $content;
};

$assignCount = 0;
$assignErrors = 0;
$completedBodies = [];
$failedFinishes = 0;
$lastAssistantText = '';
$transcriptThread = Thread::of($agent->threadId(), $agent->thread()->datadir);
$container->get(ThreadsInterface::class)->load($transcriptThread);
foreach (liveWireMessages(
    $transcriptThread->completions(),
) as $message) {
    if ($message instanceof AssistantMessage) {
        $text = trim($message->content ?? '');
        if ($text !== '') {
            $lastAssistantText = $text;
        }
    }
    if ($message instanceof ToolMessage && liveToolMessageHasName($message, 'task_deliver')) {
        $result = liveFirstToolResult($message);
        $content = $result?->content ?? '';
        $payload = json_decode($unstamp($content), true);
        if (is_array($payload) && ($payload['status'] ?? null) === 'delivered') {
            $assignCount++;
            echo "task_deliver: delivered\n";
        } else {
            $assignErrors++;
            echo 'task_deliver ERROR: ' . substr(trim($content), 0, 160) . "\n";
        }
    }
    if (($event = liveTaskEventFromMessage($message)) !== null && ($event['signal'] ?? '') === 'task_finish') {
        $body = trim((string) ($event['body'] ?? ''));
        $status = (string) ($event['status'] ?? '');
        echo 'task_finish' . ($status !== '' ? "[{$status}]" : '') . ': ' . substr($body, 0, 80) . "\n";
        if ($status === 'completed') {
            $completedBodies[] = $body;
        } elseif ($status === 'failed') {
            $failedFinishes++;
        }
    }
}

$recallOk = $completedBodies !== []
    && str_contains($completedBodies[array_key_last($completedBodies)], $code);
$ok = $assignCount === 2 && count($completedBodies) >= 2 && $recallOk;

echo "\nRESULT: " . ($ok ? 'PASS (multi-run memory)' : 'INCONCLUSIVE/FAIL') . "\n";
if (!$ok) {
    echo "diagnostics: delivered_assigns={$assignCount} assign_errors={$assignErrors} "
        . 'completed_finishes=' . count($completedBodies)
        . " failed_finishes={$failedFinishes}"
        . '; last_assistant=' . substr($lastAssistantText, 0, 160) . "\n";
}

exit($ok ? 0 : 1);
});
