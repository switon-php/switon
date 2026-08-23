<?php

declare(strict_types=1);

/**
 * Live probe: slim DeliverAck + inbox wait (no poll) after dispatch.
 *
 * Checks transcript shape and tool order — not just final assistant text.
 * Requires Swoole coroutines + configured LLM (see live_bootstrap.php).
 *
 *   php packages/agency/tests/manual/live_inbox_ack.php
 */

use Switon\Ai\Thread;
use Switon\Thread\ThreadsInterface;
use Switon\Core\Runtime;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Envelope;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

require __DIR__ . '/live_bootstrap.php';

Runner::run(function (ContainerInterface $container): void {
liveConfigureOrchestratorContainer($container);
fwrite(STDOUT, 'Coroutine runtime: ' . (Runtime::isCoroutineEnabled() ? 'on' : 'off')
    . ' (swoole: ' . (extension_loaded('swoole') ? 'loaded' : 'missing') . ")\n\n");

if (!Runtime::isCoroutineEnabled()) {
    fwrite(STDERR, "FAIL: background dispatch needs Swoole coroutines (extension loaded, xdebug mode empty).\n");
    exit(1);
}

$token = 'ACK-LIVE-' . random_int(1000, 9999);
$host = liveOpenOrchestrator($container);

$prompt = <<<TXT
Tools only.

1. Call task_deliver once:
   - name="ack-worker"
   - agent="text"
   - message="Goal: task_finish to parent with status=completed and message={$token} only."
2. STOP when the tool returns delivered — do not call task_deliver again until inbox task_finish arrives.
3. After inbox task_finish, reply with exactly: OK {$token}
TXT;

fwrite(STDOUT, "=== live_inbox_ack token={$token} ===\n\n");

liveRunInCoroutine(static function () use ($host, $prompt): void {
    $host->run(Envelope::of($prompt, liveModel($container, $host->profile()->model)));
    liveDrainDelegations($host, 120.0, 4.0);
});

/** @var list<\Switon\Ai\Message> $messages */
$transcriptThread = Thread::of($host->threadId(), $host->thread()->datadir);
$container->get(ThreadsInterface::class)->load($transcriptThread);
$messages = liveWireMessages(
    $transcriptThread->completions(),
);

$unstamp = static function (string $content): string {
    if (preg_match('/^\[\d+(?:\.\d+)?\]\s+/', $content, $m) === 1) {
        return substr($content, strlen($m[0]));
    }

    return $content;
};

$slimAckOk = false;
$fatAckFields = false;
$assignBeforeFinish = 0;
$pollToolsBeforeFinish = 0;
$taskFinishCount = 0;
$sawFirstFinish = false;
$lastAssistantText = '';

foreach ($messages as $message) {
    if ($message instanceof ToolMessage && liveToolMessageHasName($message, 'task_deliver')) {
        if (!$sawFirstFinish) {
            $assignBeforeFinish++;
            $result = liveFirstToolResult($message);
            $payload = json_decode($unstamp($result?->content ?? ''), true);
            if (is_array($payload) && ($payload['status'] ?? null) === 'delivered') {
                $keys = array_keys($payload);
                sort($keys);
                $slimAckOk = $keys === ['message', 'status'];
                $fatAckFields = isset($payload['to'], $payload['thread_id']);
            }
        }
    }
    if ($message instanceof AssistantMessage) {
        foreach ($message->toolCalls as $call) {
            if (!$sawFirstFinish && $call->name === 'task_deliver') {
                if ($assignBeforeFinish > 0) {
                    $pollToolsBeforeFinish++;
                }
            }
        }
        $text = trim($message->content ?? '');
        if ($text !== '') {
            $lastAssistantText = $text;
        }
    }
    if (($event = liveTaskEventFromMessage($message)) !== null && ($event['signal'] ?? '') === 'task_finish') {
        $taskFinishCount++;
        $sawFirstFinish = true;
    }
}

$tokenOk = str_contains($lastAssistantText, $token);

fwrite(STDOUT, 'slim ack (status+message only): ' . ($slimAckOk ? 'yes' : 'no') . "\n");
fwrite(STDOUT, 'fat ack fields (from/to/thread_id): ' . ($fatAckFields ? 'yes (unexpected)' : 'no') . "\n");
fwrite(STDOUT, 'task_deliver before first task_finish: ' . $assignBeforeFinish . "\n");
fwrite(STDOUT, 'poll/dispatch tools before first task_finish (excl first task_deliver): ' . $pollToolsBeforeFinish . "\n");
fwrite(STDOUT, 'task_finish inbox events: ' . $taskFinishCount . "\n");
fwrite(STDOUT, 'final has token: ' . ($tokenOk ? 'yes' : 'no') . "\n");
fwrite(STDOUT, 'final preview: ' . substr($lastAssistantText, 0, 160) . "\n");

$ok = $slimAckOk
    && !$fatAckFields
    && $assignBeforeFinish === 1
    && $pollToolsBeforeFinish === 0
    && $taskFinishCount >= 1
    && $tokenOk;

fwrite(STDOUT, "\nRESULT: " . ($ok ? 'PASS' : 'INCONCLUSIVE/FAIL') . "\n");

exit($ok ? 0 : 1);
});
