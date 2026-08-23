<?php

declare(strict_types=1);

/**
 * Live check: orchestrator delegates via task_deliver / task_finish.
 *
 *   php packages/agency/tests/manual/live_subagent.php
 */

use Switon\Ai\Envelope;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Thread;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;
use Switon\Thread\ThreadsInterface;

require __DIR__ . '/live_bootstrap.php';

Runner::run(function (ContainerInterface $container): void {
    $repoRoot = livePromptRoot();
    liveConfigureOrchestratorContainer($container);

    $lead = liveOpenOrchestrator($container);
    echo "thread={$lead->threadId()}\n";

    $question = <<<PROMPT
Which file defines ToolInterface?

Rules:
- You are the orchestrator only — do NOT call grep, read, bash, or any non-agent_* / non-task_* tool yourself.
- task_deliver name="finder" agent="assist" message="Goal: find which file defines interface ToolInterface. Scope: {$repoRoot}/packages only. Use grep for pattern \"interface ToolInterface\". When done, task_finish to parent with status=completed and message=absolute file path only."
- Do NOT task_deliver again until finder task_finish arrives in parent inbox.
- Report only the final file path to me.
PROMPT;

    echo "=== live_subagent (task delegation) ===\n\n";

    liveRunInCoroutine(static function () use ($lead, $question): void {
        $lead->run(Envelope::of($question, liveModel($container, $lead->profile()->model)));
        liveDrainDelegations($lead);
    });

    $toolCalls = [];
    $finishBody = '';
    $lastAssistantText = '';

    $transcriptThread = Thread::of($lead->threadId(), $lead->thread()->datadir);
    $container->get(ThreadsInterface::class)->load($transcriptThread);
    foreach (liveWireMessages(
        $transcriptThread->completions(),
    ) as $message) {
        if ($message instanceof AssistantMessage) {
            foreach ($message->toolCalls as $call) {
                $toolCalls[$call->name] = ($toolCalls[$call->name] ?? 0) + 1;
            }
            $text = trim($message->content ?? '');
            if ($text !== '') {
                $lastAssistantText = $text;
            }
        }
        $event = liveTaskEventFromMessage($message);
        if ($event !== null && ($event['signal'] ?? '') === 'task_finish' && ($event['status'] ?? '') === 'completed') {
            $finishBody = (string) ($event['body'] ?? '');
        }
    }

    $pathOk = str_contains($finishBody, 'packages/tooling/src/ToolInterface.php')
        || str_contains($lastAssistantText, 'packages/tooling/src/ToolInterface.php');

    echo "--- summary ---\n";
    echo 'tools: ' . json_encode($toolCalls, JSON_UNESCAPED_UNICODE) . "\n";
    echo 'task_finish body: ' . ($finishBody !== '' ? $finishBody : '(missing)') . "\n";
    echo 'path ok: ' . ($pathOk ? 'YES' : 'no') . "\n";

    $ok = ($toolCalls['task_deliver'] ?? 0) > 0 && $pathOk;
    echo "\nRESULT: " . ($ok ? 'PASS' : 'INCONCLUSIVE/FAIL') . "\n";

    exit($ok ? 0 : 1);
});
