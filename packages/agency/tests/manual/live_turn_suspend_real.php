<?php

declare(strict_types=1);

/**
 * Real-LLM live: form_ask suspend → external reply → commit; dump transcript.
 *
 *   php packages/agency/tests/manual/live_turn_suspend_real.php
 */

require __DIR__ . '/live_bootstrap.php';

use Switon\Agency\Agent;
use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Agency\Tool\FormAskTool;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Suspension\ToolReplyContinuation;
use Switon\Ai\Profile;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Suspension\ToolReplyInterrupt;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Ai\Message\UserMessage;
use Switon\Core\FilesystemInterface;
use Switon\Core\Json;
use Switon\Core\PathAliasInterface;
use Switon\Thread\Threads;
use Switon\Thread\ThreadsInterface;
use Switon\Ai\Envelope;

function suspendDump(string $label, ?Turn $turn): void
{
    echo "\n--- {$label} ---\n";
    if ($turn === null) {
        echo "(no turn)\n";

        return;
    }
    echo 'status=' . $turn->status
        . ' interrupt=' . ($turn->interrupt ? $turn->interrupt::class : 'null')
        . ' completions=' . count($turn->completions) . "\n";
    if ($turn->interrupt instanceof ToolReplyInterrupt) {
        echo 'callId=' . $turn->interrupt->callId
            . ' name=' . $turn->interrupt->name
            . ' args.id=' . ($turn->interrupt->arguments['id'] ?? '') . "\n";
    }
    foreach ($turn->completions as $idx => $c) {
        $in = $c->input;
        $out = $c->output;
        $inKind = $in === null ? 'null' : basename(str_replace('\\', '/', $in::class));
        $outKind = $out === null ? 'null' : basename(str_replace('\\', '/', $out::class));
        $extra = '';
        if ($in instanceof ToolMessage) {
            foreach ($in->results as $r) {
                $kind = $r->content instanceof \Throwable
                    ? $r->content::class
                    : (is_array($r->content) ? (string) ($r->content['class'] ?? 'ok') : 'ok');
                $extra .= ' tool=' . $r->id . ':' . $kind . ':' . substr(Json::text($r->wire()), 0, 80);
            }
        }
        if ($out instanceof AssistantMessage) {
            $calls = array_map(static fn ($t) => $t->id . ':' . $t->name, $out->toolCalls);
            $text = trim((string) ($out->content ?? ''));
            if ($text !== '') {
                $extra .= ' text=' . substr($text, 0, 80);
            }
            if ($calls !== []) {
                $extra .= ' calls=[' . implode(',', $calls) . ']';
            }
        }
        echo "  [{$idx}] step={$c->step} in={$inKind} out={$outKind}{$extra}\n";
    }
}

$ok = \Switon\Cli\Runner::run(function (\Switon\Di\ContainerInterface $container): bool {
    liveBindFormAskTypes($container);
    liveBindTools($container, ['form_ask']);
    liveDisableMcp($container);
    liveRegisterAgentPackage($container);
    liveStartConsole($container);

    $profile = new Profile(
        id: 'live-suspend-form',
        description: 'Live form_ask suspend probe',
        model: 'main',
        skills: [],
        maxSteps: 6,
        prompt: 'You are a concise assistant. When the user asks for a choice, call form_ask once. After you receive the tool result, acknowledge the chosen value in one short sentence.',
        tools: ['form_ask'],
    );

    $threads = $container->get(ThreadsInterface::class);
    $fs = $container->get(FilesystemInterface::class);
    $paths = $container->get(PathAliasInterface::class);
    /** @var Agent $agent */
    $agent = $container->get(RecordingAgentManager::class)->mint(['profile' => $profile]);
    liveTrackOpenAgent($agent);

    echo "thread=" . $agent->threadId() . "\n";
    echo "datadir=" . $agent->thread()->datadir . "\n";

    $prompt = <<<'TXT'
Use form_ask exactly once (no prose before the tool call).
id=live-real-pick
title=Live suspend check
One radio field: name=choice label=Pick options a and b.
TXT;

    $agent->run(Envelope::of($prompt, liveModel($container, $agent->profile()->model)));
    $turn = $agent->thread()->lastTurn();
    suspendDump('after form_ask (expect suspend)', $turn);

    $resolved = $paths->resolve($agent->thread()->datadir);
    $openFile = is_file($resolved . '/' . Threads::TURN_FILE);
    echo 'open_turn_file=' . ($openFile ? 'yes' : 'no') . "\n";

    $sealed = liveTranscriptJsonlTurns($fs, $resolved);
    suspendDump('transcript.jsonl tip after suspend', $sealed === [] ? null : $sealed[array_key_last($sealed)]);

    if (!($turn?->interrupt instanceof ToolReplyInterrupt)) {
        echo "FAIL: expected ToolReplyInterrupt suspend\n";

        return false;
    }
    if ($turn->status !== TurnStatus::Suspended || !$openFile) {
        echo "FAIL: expected resumable + open turn file\n";

        return false;
    }

    $callId = $turn->interrupt->callId;
    $formId = (string) ($turn->interrupt->arguments['id'] ?? 'live-real-pick');
    echo "replying callId={$callId} formId={$formId}\n";

    $agent->run(Envelope::of(ToolReplyContinuation::of($callId, [
        'outcome' => 'answered',
        'id' => $formId,
        'values' => ['choice' => 'b'],
    ]), liveModel($container, $agent->profile()->model)));

    $committed = $agent->thread()->lastTurn();
    suspendDump('after reply (expect commit)', $committed);
    $sealed2 = liveTranscriptJsonlTurns($fs, $resolved);
    suspendDump('transcript.jsonl tip after commit', $sealed2 === [] ? null : $sealed2[array_key_last($sealed2)]);
    echo 'open_turn_file_after=' . (is_file($resolved . '/' . Threads::TURN_FILE) ? 'yes' : 'no') . "\n";

    $hasReply = false;
    $assistant = '';
    if ($committed !== null) {
        foreach ($committed->completions as $c) {
            if ($c->input instanceof ToolMessage) {
                foreach ($c->input->results as $r) {
                    if ($r->id === $callId && str_contains(Json::text($r->content), 'answered')) {
                        $hasReply = true;
                    }
                }
            }
            if ($c->output instanceof AssistantMessage) {
                $t = trim((string) ($c->output->content ?? ''));
                if ($t !== '') {
                    $assistant = $t;
                }
            }
        }
    }

    echo "assistant_final={$assistant}\n";
    $pass = $committed?->status === TurnStatus::Completed
        && $committed->interrupt === null
        && $hasReply
        && !is_file($resolved . '/' . Threads::TURN_FILE)
        && $assistant !== '';

    return $pass;
});

echo "\nRESULT: " . ($ok ? 'PASS' : 'FAIL') . "\n";
exit($ok ? 0 : 1);
