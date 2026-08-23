<?php

declare(strict_types=1);

/**
 * Live probe: two model steps. The budget notice is on the tool results fed into the last call.
 *
 *   php packages/agency/tests/manual/live_step_budget.php
 *   php packages/agency/tests/manual/live_step_budget.php deepseek/pro
 */

use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Ai\Envelope;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Part\TextPart;
use Switon\Ai\Profile;
use Switon\Ai\Thread;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Llm\Event\CompletionRequesting;
use Switon\Llm\Event\CompletionResponded;
use Switon\Thread\ThreadsInterface;

require __DIR__ . '/live_bootstrap.php';

$modelPick = $argv[1] ?? 'main';
$resumeId = $argv[2] ?? '';
// Normalize separators for the prompt: Windows `dirname()` yields backslashes, which would mix
// with the `/packages/...` suffixes written below.
$repoRoot = livePromptRoot();

Runner::run(function (ContainerInterface $container) use ($modelPick, $resumeId, $repoRoot): void {
    liveBindTools($container, ['grep', 'read']);
    liveRegisterAgentPackage($container);

    $dispatcher = $container->get(ListenerProviderInterface::class);

    $step = 0;
    $dispatcher->on(null, static function (CompletionRequesting $event) use (&$step): void {
        $step++;
        $tools = array_keys($event->charter->tools);
        echo sprintf(
            "  [request %d] tools=%s toolChoice=%s prompt_msgs=%d\n",
            $step,
            $tools === [] ? '[]' : implode(',', $tools),
            var_export($event->charter->toolChoice, true),
            $event->charter->prompt !== '' ? 1 : 0,
        );
    });

    $dispatcher->on(null, static function (CompletionResponded $event) use (&$step): void {
        $out = $event->completion->output;
        $toolNames = [];
        $text = '';
        if ($out instanceof AssistantMessage) {
            $text = trim((string) ($out->content ?? ''));
            foreach ($out->toolCalls as $toolCall) {
                $toolNames[] = $toolCall->name;
            }
        } elseif ($out instanceof \Switon\Ai\Signal\AssistantAbortSignal) {
            $text = 'interrupt.' . $out->kind . ' ' . trim($out->body);
        }
        echo sprintf(
            "  [respond %d] finish=%s tools=[%s] text=%s\n",
            $step,
            var_export($event->completion->finishReason, true),
            implode(',', $toolNames),
            $text === '' ? '(empty)' : json_encode(mb_substr($text, 0, 240), JSON_UNESCAPED_UNICODE),
        );
    });

    $profile = Profile::decode([
        'id' => 'step-budget-live',
        'prompt' => 'Answer from the repository. Use grep and read. Keep replies short.',
        'tools' => ['grep', 'read'],
        'skills' => [],
        'model' => $modelPick,
        'max_steps' => 2,
    ]);
    $mint = ['profile' => $profile];
    if ($resumeId !== '') {
        $thread = Thread::of($resumeId, '@threads/' . $resumeId);
        $container->get(ThreadsInterface::class)->load($thread);
        $mint['thread'] = $thread;
    }
    $agent = $container->get(RecordingAgentManager::class)->mint($mint);

    $prompt = $resumeId === '' ? <<<TXT
Two facts, from source, no guessing. Paths must be absolute:
1. The declared return type of Switon\Agency\Loop::dispatchToolCalls in {$repoRoot}/packages/agency/src/Loop.php.
2. Every public property on Switon\Ai\ToolCall in {$repoRoot}/packages/ai/src/ToolCall.php.
Report both.
TXT
        : <<<'TXT'
On the previous turn you were told: "this is the last chance this turn. Wrap up with the answer now. Do not call more tools." You called read anyway, and the turn was aborted because the step budget was exhausted. Why did you not follow that instruction? Answer in text. Do not use tools.
TXT;

    $model = $agent->profile()->model !== '' ? $agent->profile()->model : 'main';

    echo "=== run (model={$modelPick}, maxSteps=2" . ($resumeId === '' ? '' : ", resume={$resumeId}") . ") ===\n";
    $agent->run(Envelope::of($prompt, liveModel($container, $model)));

    echo "\n--- last turn ---\n";
    $turns = $agent->thread()->turns;
    $lastTi = array_key_last($turns);
    foreach ($turns as $ti => $turn) {
        if ($ti !== $lastTi) {
            continue;
        }
        echo "turn[{$ti}] status={$turn->status->value} completions=" . count($turn->completions) . "\n";
        foreach ($turn->completions as $ci => $completion) {
            $in = $completion->input;
            $out = $completion->output;
            $inLabel = $in::class;
            if ($in instanceof ToolMessage) {
                $names = array_map(static fn ($r) => $r->name, $in->results);
                $inLabel .= ' tools=[' . implode(',', $names) . ']';
                foreach ($in->results as $result) {
                    foreach ($result->parts as $part) {
                        if ($part instanceof TextPart) {
                            $inLabel .= ' context=' . json_encode(mb_substr($part->text, 0, 60), JSON_UNESCAPED_UNICODE);
                        }
                    }
                }
            }
            $outLabel = $out === null ? 'null' : $out::class;
            if ($out instanceof AssistantMessage) {
                $tools = array_map(static fn ($c) => $c->name . '(' . json_encode($c->arguments, JSON_UNESCAPED_UNICODE) . ')', $out->toolCalls);
                $text = trim((string) ($out->content ?? ''));
                $outLabel .= ' tools=[' . implode(' ', $tools) . '] text='
                    . ($text === '' ? '(empty)' : json_encode($text, JSON_UNESCAPED_UNICODE));
            } elseif ($out instanceof \Switon\Ai\Signal\AssistantAbortSignal) {
                $outLabel .= ' kind=' . $out->kind . ' body='
                    . json_encode(mb_substr($out->body, 0, 120), JSON_UNESCAPED_UNICODE);
            }
            echo "  c[{$ci}] in={$inLabel}\n       out={$outLabel}\n";
        }
    }

    echo "\nDONE\n";
});
