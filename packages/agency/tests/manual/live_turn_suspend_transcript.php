<?php

declare(strict_types=1);

/**
 * Stub-LLM live audit: suspend → continue for Retry / ToolReply / ToolReview, dump transcript.
 *
 *   php packages/agency/tests/manual/live_turn_suspend_transcript.php
 */

require __DIR__ . '/live_bootstrap.php';

use Switon\Agency\Agent;
use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Agency\Tests\Fixtures\AlwaysReviewTool;
use Switon\Agency\Tests\Fixtures\StubTool;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Agency\Tool\FormAskTool;
use Switon\Ai\Suspension\RetryContinuation;
use Switon\Ai\Suspension\ToolReviewContinuation;
use Switon\Ai\Suspension\ToolReplyContinuation;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\ToolResult;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Ai\Message\UserMessage;
use Switon\Core\Json;
use Switon\Core\PathAliasInterface;
use Switon\Thread\Threads;
use Switon\Thread\ThreadsInterface;
use Switon\Ai\Envelope;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;
use Switon\Ai\Thread;

function dumpTurn(string $label, ?Turn $turn): void
{
    echo "\n--- {$label} ---\n";
    if ($turn === null) {
        echo "(no turn)\n";

        return;
    }
    echo 'status=' . $turn->status
        . ' interrupt=' . ($turn->interrupt ? $turn->interrupt::class : 'null')
        . ' completions=' . count($turn->completions) . "\n";
    if ($turn->interrupt !== null) {
        $bits = ['class' => $turn->interrupt::class];
        foreach (get_object_vars($turn->interrupt) as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $bits[$k] = $v;
            } elseif (is_array($v)) {
                $bits[$k] = Json::stringify($v);
            }
        }
        echo 'interrupt_fields=' . Json::stringify($bits) . "\n";
    }
    foreach ($turn->completions as $idx => $c) {
        $in = $c->input;
        $out = $c->output;
        $inKind = $in === null ? 'null' : basename(str_replace('\\', '/', $in::class));
        $outKind = $out === null ? 'null' : basename(str_replace('\\', '/', $out::class));
        $extra = '';
        if ($in instanceof ToolMessage) {
            $parts = [];
            foreach ($in->results as $r) {
                $kind = $r->content instanceof \Throwable
                    ? $r->content::class
                    : (is_array($r->content) ? (string) ($r->content['class'] ?? 'ok') : 'ok');
                $parts[] = $r->id . ':' . $kind . ':' . substr(Json::text($r->wire()), 0, 60);
            }
            $extra .= ' tools=[' . implode(' | ', $parts) . ']';
        }
        if ($out instanceof AssistantMessage) {
            $calls = array_map(static fn ($t) => $t->id . ':' . $t->name, $out->toolCalls);
            $text = trim((string) ($out->content ?? ''));
            $extra .= ' assistant_text=' . substr($text, 0, 40);
            if ($calls !== []) {
                $extra .= ' calls=[' . implode(',', $calls) . ']';
            }
        }
        echo "  [{$idx}] step={$c->step} in={$inKind} out={$outKind}{$extra}\n";
    }
}

function dumpDisk(ThreadsInterface $threads, PathAliasInterface $paths, Agent $agent, string $label): void
{
    $datadir = $agent->thread()->datadir;
    $resolved = $paths->resolve($datadir);
    $openFile = $resolved . '/' . Threads::TURN_FILE;
    echo "\n### disk {$label}\n";
    echo 'open_turn_file=' . (is_file($openFile) ? 'yes' : 'no') . "\n";
    $loaded = Thread::of($agent->threadId(), $datadir);
    $threads->load($loaded);
    dumpTurn('load.lastTurn', $loaded->lastTurn());
}

$pass = 0;
$fail = 0;

$runScenario = static function (string $id, callable $fn) use (&$pass, &$fail): void {
    echo "\n========== {$id} ==========\n";
    try {
        $ok = Runner::run(static function (ContainerInterface $container) use ($fn): bool {
            return $fn($container);
        });
    } catch (Throwable $e) {
        echo 'UNCAUGHT: ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
        $ok = false;
    }
    echo 'RESULT: ' . ($ok ? 'PASS' : 'FAIL') . "\n";
    $ok ? $pass++ : $fail++;
};

$runScenario('llm-fail-retry', static function (ContainerInterface $container): bool {
    $stub = new StubLlm();
    $container->set(\Switon\Ai\LlmInterface::class, $stub);
    $container->set(RecordingAgentManager::class, RecordingAgentManager::class);
    $stub->responses[] = static function (): never {
        throw new RuntimeException('llm down before tools');
    };
    $stub->responses[] = StubLlm::assistant('recovered-before-tools');
    $threads = $container->get(ThreadsInterface::class);
    $paths = $container->get(PathAliasInterface::class);
    $agent = $container->get(RecordingAgentManager::class)->mint([]);
    liveTrackOpenAgent($agent);

    $threw = false;
    try {
        $agent->run(Envelope::of('hello', liveModel($container, $agent->profile()->model)));
    } catch (Throwable) {
        $threw = true;
    }
    echo 'run_threw=' . ($threw ? 'yes' : 'no') . "\n";
    dumpTurn('memory after suspend', $agent->thread()->lastTurn());
    dumpDisk($threads, $paths, $agent, 'suspended');

    $turn = $agent->thread()->lastTurn();
    $ok = $threw
        && $turn !== null
        && $turn->status === TurnStatus::Suspended
        && $turn->interrupt instanceof \Switon\Ai\Suspension\RetryInterrupt
        && is_file($paths->resolve($agent->thread()->datadir) . '/' . Threads::TURN_FILE);

    $agent->run(Envelope::of(RetryContinuation::of(), liveModel($container, $agent->profile()->model)));
    dumpTurn('memory after retry', $agent->thread()->lastTurn());
    dumpDisk($threads, $paths, $agent, 'after retry');
    $tip = $agent->thread()->lastTurn();
    $text = '';
    if ($tip !== null) {
        foreach (array_reverse($tip->completions) as $c) {
            if ($c->output instanceof AssistantMessage) {
                $text = (string) ($c->output->content ?? '');
                break;
            }
        }
    }
    echo "final_assistant={$text}\n";

    return $ok
        && $tip?->status === TurnStatus::Completed
        && $tip->interrupt === null
        && $text === 'recovered-before-tools'
        && !is_file($paths->resolve($agent->thread()->datadir) . '/' . Threads::TURN_FILE);
});

$runScenario('tool-then-llm-fail-retry', static function (ContainerInterface $container): bool {
    $stub = new StubLlm();
    $container->set(\Switon\Ai\LlmInterface::class, $stub);
    $container->set(RecordingAgentManager::class, RecordingAgentManager::class);
    liveBindTools($container, [
        'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'pong'),
    ]);
    $stub->responses[] = StubLlm::assistantWithToolCall('echo', ['text' => 'hi'], 'call_echo_1');
    $stub->responses[] = static function (): never {
        throw new RuntimeException('llm down after tool');
    };
    $stub->responses[] = StubLlm::assistant('recovered-after-tool');
    $threads = $container->get(ThreadsInterface::class);
    $paths = $container->get(PathAliasInterface::class);
    $agent = $container->get(RecordingAgentManager::class)->mint([]);
    $profile = clone $agent->profile();
    $profile->tools = [...$profile->tools, 'echo'];
    liveBindProfileOverride($agent, $profile, $container);
    liveTrackOpenAgent($agent);

    try {
        $agent->run(Envelope::of('echo then die', liveModel($container, $agent->profile()->model)));
    } catch (Throwable) {
    }
    dumpTurn('memory after suspend', $agent->thread()->lastTurn());
    dumpDisk($threads, $paths, $agent, 'suspended');

    $turn = $agent->thread()->lastTurn();
    $hasPong = false;
    $dupHung = 0;
    if ($turn !== null) {
        foreach ($turn->completions as $c) {
            if ($c->input instanceof ToolMessage) {
                foreach ($c->input->results as $r) {
                    if ($r->id === 'call_echo_1' && str_contains(Json::text($r->content), 'pong')) {
                        $hasPong = true;
                    }
                }
            }
            if ($c->output instanceof AssistantMessage && $c->output->toolCalls !== []) {
                foreach ($c->output->toolCalls as $call) {
                    if ($call->id === 'call_echo_1') {
                        $dupHung++;
                    }
                }
            }
        }
    }
    echo "has_pong={$hasPong} echo_assistant_rows={$dupHung}\n";

    $agent->run(Envelope::of(RetryContinuation::of(), liveModel($container, $agent->profile()->model)));
    dumpTurn('memory after retry', $agent->thread()->lastTurn());
    dumpDisk($threads, $paths, $agent, 'after retry');
    $committedThread = Thread::of($agent->threadId(), $agent->thread()->datadir);
    $threads->load($committedThread);
    $committed = $committedThread->lastTurn();
    $stillPong = false;
    if ($committed !== null) {
        foreach ($committed->completions as $c) {
            if ($c->input instanceof ToolMessage) {
                foreach ($c->input->results as $r) {
                    if ($r->id === 'call_echo_1' && str_contains(Json::text($r->content), 'pong')) {
                        $stillPong = true;
                    }
                }
            }
        }
    }

    return $hasPong && $stillPong && $dupHung === 1 && $committed?->status === TurnStatus::Completed;
});

$runScenario('form-ask-reply', static function (ContainerInterface $container): bool {
    $stub = new StubLlm();
    $container->set(\Switon\Ai\LlmInterface::class, $stub);
    $container->set(RecordingAgentManager::class, RecordingAgentManager::class);
    $stub->responses[] = StubLlm::assistantWithToolCall(
        'form_ask',
        [
            'id' => 'live-pick',
            'fields' => [[
                'name' => 'choice',
                'label' => 'Pick',
                'type' => 'radio',
                'options' => ['a', 'b'],
            ]],
        ],
        'call_form_live',
    );
    $stub->responses[] = StubLlm::assistant('got-reply');
    liveBindFormAskTypes($container, ['radio']);
    liveBindTools($container, [
        'form_ask' => FormAskTool::class,
        'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'x'),
    ]);
    $threads = $container->get(ThreadsInterface::class);
    $paths = $container->get(PathAliasInterface::class);
    $agent = $container->get(RecordingAgentManager::class)->mint([]);
    $profile = clone $agent->profile();
    $profile->tools = ['form_ask'];
    liveBindProfileOverride($agent, $profile, $container);
    liveTrackOpenAgent($agent);

    $agent->run(Envelope::of('ask', liveModel($container, $agent->profile()->model)));
    dumpTurn('memory after form suspend', $agent->thread()->lastTurn());
    dumpDisk($threads, $paths, $agent, 'form suspended');

    $turn = $agent->thread()->lastTurn();
    $okSuspend = $turn?->interrupt instanceof \Switon\Ai\Suspension\ToolReplyInterrupt
        && $turn->status === TurnStatus::Suspended
        && ($turn->interrupt->callId ?? '') === 'call_form_live';

    $prematureTool = false;
    foreach ($turn->completions ?? [] as $c) {
        if ($c->input instanceof ToolMessage) {
            $prematureTool = true;
        }
    }
    echo 'premature_tool_tip=' . ($prematureTool ? 'yes' : 'no') . "\n";

    $agent->run(Envelope::of(ToolReplyContinuation::of('call_form_live', [
        'outcome' => 'answered',
        'id' => 'live-pick',
        'values' => ['choice' => 'a'],
    ]), liveModel($container, $agent->profile()->model)));
    dumpTurn('memory after reply', $agent->thread()->lastTurn());
    dumpDisk($threads, $paths, $agent, 'after reply');

    $committedThread = Thread::of($agent->threadId(), $agent->thread()->datadir);
    $threads->load($committedThread);
    $committed = $committedThread->lastTurn();
    $hasReply = false;
    if ($committed !== null) {
        foreach ($committed->completions as $c) {
            if ($c->input instanceof ToolMessage) {
                foreach ($c->input->results as $r) {
                    if ($r->id === 'call_form_live' && str_contains(Json::text($r->content), 'answered')) {
                        $hasReply = true;
                    }
                }
            }
        }
    }

    return $okSuspend && !$prematureTool && $hasReply && $committed?->status === TurnStatus::Completed;
});

$runScenario('review-deny', static function (ContainerInterface $container): bool {
    $stub = new StubLlm();
    $container->set(\Switon\Ai\LlmInterface::class, $stub);
    $container->set(RecordingAgentManager::class, RecordingAgentManager::class);
    liveBindTools($container, [
        'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'should-not-run'),
    ]);
    $review = new AlwaysReviewTool();
    liveRebind($container, \Switon\Agency\ExtensionsInterface::class, new \Switon\Agency\Tests\Fixtures\MutableExtensionRegistry($review));
    $stub->responses[] = StubLlm::assistantWithToolCall('echo', ['text' => 'secret'], 'call_review_1');
    $stub->responses[] = StubLlm::assistant('after-deny');
    $threads = $container->get(ThreadsInterface::class);
    $paths = $container->get(PathAliasInterface::class);
    $agent = $container->get(RecordingAgentManager::class)->mint([]);
    $profile = clone $agent->profile();
    $profile->tools = [...$profile->tools, 'echo'];
    liveBindProfileOverride($agent, $profile, $container);
    liveTrackOpenAgent($agent);

    $agent->run(Envelope::of('need review', liveModel($container, $agent->profile()->model)));
    dumpTurn('memory after review suspend', $agent->thread()->lastTurn());
    dumpDisk($threads, $paths, $agent, 'review suspended');

    $turn = $agent->thread()->lastTurn();
    $okSuspend = $turn?->interrupt instanceof \Switon\Ai\Suspension\ToolReviewInterrupt
        && $turn->interrupt->callId === 'call_review_1'
        && $review->seen === ['call_review_1'];

    $agent->run(Envelope::of(ToolReviewContinuation::deny('call_review_1', 'blocked'), liveModel($container, $agent->profile()->model)));
    dumpTurn('memory after deny', $agent->thread()->lastTurn());
    dumpDisk($threads, $paths, $agent, 'after deny');

    $committedThread = Thread::of($agent->threadId(), $agent->thread()->datadir);
    $threads->load($committedThread);
    $committed = $committedThread->lastTurn();
    $denyOk = false;
    if ($committed !== null) {
        foreach ($committed->completions as $c) {
            if ($c->input instanceof ToolMessage) {
                foreach ($c->input->results as $r) {
                    if ($r->id === 'call_review_1'
                        && $r->isError
                        && (
                            $r->content instanceof \Switon\Ai\Exception\ToolDeniedException
                            || (is_array($r->content) && ($r->content['class'] ?? null) === \Switon\Ai\Exception\ToolDeniedException::class)
                        )
                        && str_contains(Json::text($r->wire()), 'blocked')
                    ) {
                        $denyOk = true;
                    }
                }
            }
        }
    }

    return $okSuspend && $denyOk && $committed?->status === TurnStatus::Completed;
});

$runScenario('review-allow-then-form', static function (ContainerInterface $container): bool {
    $stub = new StubLlm();
    $container->set(\Switon\Ai\LlmInterface::class, $stub);
    $container->set(RecordingAgentManager::class, RecordingAgentManager::class);
    $review = new AlwaysReviewTool();
    liveRebind($container, \Switon\Agency\ExtensionsInterface::class, new \Switon\Agency\Tests\Fixtures\MutableExtensionRegistry($review));
    $stub->responses[] = StubLlm::assistantWithToolCall(
        'form_ask',
        [
            'id' => 'reviewed-pick',
            'fields' => [[
                'name' => 'choice',
                'label' => 'Pick',
                'type' => 'radio',
                'options' => ['a', 'b'],
            ]],
        ],
        'call_form_review',
    );
    $stub->responses[] = StubLlm::assistant('after-allow-reply');
    liveBindFormAskTypes($container, ['radio']);
    liveBindTools($container, [
        'form_ask' => FormAskTool::class,
    ]);
    $threads = $container->get(ThreadsInterface::class);
    $paths = $container->get(PathAliasInterface::class);
    $agent = $container->get(RecordingAgentManager::class)->mint([]);
    $profile = clone $agent->profile();
    $profile->tools = ['form_ask'];
    liveBindProfileOverride($agent, $profile, $container);
    liveTrackOpenAgent($agent);

    $agent->run(Envelope::of('ask reviewed', liveModel($container, $agent->profile()->model)));
    dumpTurn('after review suspend', $agent->thread()->lastTurn());
    dumpDisk($threads, $paths, $agent, 'review');

    $agent->run(Envelope::of(ToolReviewContinuation::allow('call_form_review'), liveModel($container, $agent->profile()->model)));
    dumpTurn('after allow → form suspend', $agent->thread()->lastTurn());
    dumpDisk($threads, $paths, $agent, 'form');

    $turn = $agent->thread()->lastTurn();
    $ok = $turn?->interrupt instanceof \Switon\Ai\Suspension\ToolReplyInterrupt
        && $turn->interrupt->callId === 'call_form_review';

    $agent->run(Envelope::of(ToolReplyContinuation::of('call_form_review', [
        'outcome' => 'answered',
        'id' => 'reviewed-pick',
        'values' => ['choice' => 'b'],
    ]), liveModel($container, $agent->profile()->model)));
    dumpTurn('after form reply', $agent->thread()->lastTurn());
    dumpDisk($threads, $paths, $agent, 'completed');

    return $ok && $agent->thread()->lastTurn()?->status === TurnStatus::Completed;
});

echo "\n=== summary pass={$pass} fail={$fail} ===\n";
exit($fail > 0 ? 1 : 0);
