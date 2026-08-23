<?php

declare(strict_types=1);

/**
 * Real-LLM live: force first stream fail → RetryInterrupt suspend → retry → real LLM commit.
 *
 *   php packages/agency/tests/manual/live_turn_suspend_retry_real.php
 */

require __DIR__ . '/live_bootstrap.php';

use Switon\Agency\Agent;
use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Charter;
use Switon\Ai\Suspension\RetryContinuation;
use Switon\Ai\Thread;
use Switon\Ai\Completion;
use Switon\Ai\LlmInterface;
use Switon\Ai\Profile;
use Switon\Ai\Suspension\RetryInterrupt;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Ai\Message\UserMessage;
use Switon\Core\FilesystemInterface;
use Switon\Core\Json;
use Switon\Core\PathAliasInterface;
use Switon\Llm\LlmProvidersInterface;
use Switon\Thread\Threads;
use Switon\Thread\ThreadsInterface;
use Switon\Ai\Envelope;

/** First stream/complete throws once; later calls hit the real LLM. */
class LiveFailOnceLlm implements LlmInterface
{
    protected int $failsLeft = 1;

    public function __construct(protected LlmInterface $inner)
    {
    }

    public function failsLeft(): int
    {
        return $this->failsLeft;
    }

    public function complete(Thread $thread, Charter $charter): Completion
    {
        if ($this->failsLeft > 0) {
            $this->failsLeft--;
            throw new RuntimeException('live forced llm fail (complete)');
        }

        return $this->inner->complete($thread, $charter);
    }

    public function stream(
        Thread $thread,
        Charter $charter,
        ?callable $onDelta = null,
        ?callable $shouldAbort = null,
    ): Completion {
        if ($this->failsLeft > 0) {
            $this->failsLeft--;
            throw new RuntimeException('live forced llm fail (stream)');
        }

        return $this->inner->stream($thread, $charter, $onDelta, $shouldAbort);
    }

    public function model(): \Switon\Ai\Model
    {
        return $this->inner->model();
    }

    public function window(): int
    {
        return $this->inner->window();
    }
}

class LiveFailOnceProviders implements LlmProvidersInterface
{
    protected ?LiveFailOnceLlm $wrapped = null;

    public function __construct(protected LlmProvidersInterface $inner)
    {
    }

    public function add(LlmInterface $llm, array $alias = []): void
    {
        $this->inner->add($llm, $alias);
    }

    public function get(string $model): LlmInterface
    {
        return $this->wrapped ??= new LiveFailOnceLlm($this->inner->get($model));
    }

    public function has(string $model): bool
    {
        return $this->inner->has($model);
    }

    public function all(): array
    {
        return $this->inner->all();
    }

    public function failOnce(): ?LiveFailOnceLlm
    {
        return $this->wrapped;
    }
}

function retryDump(string $label, ?Turn $turn): void
{
    echo "\n--- {$label} ---\n";
    if ($turn === null) {
        echo "(no turn)\n";

        return;
    }
    echo 'status=' . $turn->status
        . ' interrupt=' . ($turn->interrupt ? $turn->interrupt::class : 'null')
        . ' completions=' . count($turn->completions) . "\n";
    if ($turn->interrupt instanceof RetryInterrupt) {
        echo 'reason=' . $turn->interrupt->reason
            . ' detail=' . substr($turn->interrupt->detail, 0, 120) . "\n";
    }
    foreach ($turn->completions as $idx => $c) {
        $in = $c->input;
        $out = $c->output;
        $inKind = $in === null ? 'null' : basename(str_replace('\\', '/', $in::class));
        $outKind = $out === null ? 'null' : basename(str_replace('\\', '/', $out::class));
        $extra = '';
        if ($out instanceof AssistantMessage) {
            $text = trim((string) ($out->content ?? ''));
            if ($text !== '') {
                $extra .= ' text=' . substr($text, 0, 100);
            }
            $calls = array_map(static fn ($t) => $t->id . ':' . $t->name, $out->toolCalls);
            if ($calls !== []) {
                $extra .= ' calls=[' . implode(',', $calls) . ']';
            }
        }
        echo "  [{$idx}] step={$c->step} in={$inKind} out={$outKind}{$extra}\n";
    }
}

$ok = \Switon\Cli\Runner::run(function (\Switon\Di\ContainerInterface $container): bool {
    liveBindTools($container, []);
    liveDisableMcp($container);
    liveRegisterAgentPackage($container);

    $realProviders = $container->get(LlmProvidersInterface::class);
    $failProviders = new LiveFailOnceProviders($realProviders);
    $container->replace(LlmProvidersInterface::class, $failProviders);

    liveStartConsole($container);

    $profile = new Profile(
        id: 'live-suspend-retry',
        description: 'Live retry suspend probe',
        model: 'main',
        skills: [],
        maxSteps: 4,
        prompt: 'You are a concise assistant. Reply in one short sentence. No tools.',
    );

    $threads = $container->get(ThreadsInterface::class);
    $fs = $container->get(FilesystemInterface::class);
    $paths = $container->get(PathAliasInterface::class);
    /** @var Agent $agent */
    $agent = $container->get(RecordingAgentManager::class)->mint(['profile' => $profile]);
    liveTrackOpenAgent($agent);

    echo "thread=" . $agent->threadId() . "\n";
    echo "datadir=" . $agent->thread()->datadir . "\n";

    $threw = false;
    try {
        $agent->run(Envelope::of('Say hello in exactly one word.', liveModel($container, $agent->profile()->model)));
    } catch (Throwable $e) {
        $threw = true;
        echo 'run_threw=' . $e->getMessage() . "\n";
    }

    $turn = $agent->thread()->lastTurn();
    retryDump('after forced fail (expect RetryInterrupt suspend)', $turn);
    $resolved = $paths->resolve($agent->thread()->datadir);
    echo 'open_turn_file=' . (is_file($resolved . '/' . Threads::TURN_FILE) ? 'yes' : 'no') . "\n";
    $sealed = liveTranscriptJsonlTurns($fs, $resolved);
    retryDump('transcript.jsonl tip after suspend', $sealed === [] ? null : $sealed[array_key_last($sealed)]);

    $okSuspend = $threw
        && $turn?->interrupt instanceof RetryInterrupt
        && $turn->status === TurnStatus::Suspended
        && $turn->interrupt->reason === RetryInterrupt::REASON_LLM_FAILED
        && is_file($resolved . '/' . Threads::TURN_FILE);

    if (!$okSuspend) {
        echo "FAIL: suspend shape\n";

        return false;
    }

    echo "retry accept (real LLM)… failsLeft_before=" . ($failProviders->failOnce()?->failsLeft() ?? -1) . "\n";
    $agent->run(Envelope::of(RetryContinuation::of(), liveModel($container, $agent->profile()->model)));

    $committed = $agent->thread()->lastTurn();
    retryDump('after retry (expect commit + real reply)', $committed);
    $sealed2 = liveTranscriptJsonlTurns($fs, $resolved);
    retryDump('transcript.jsonl tip after commit', $sealed2 === [] ? null : $sealed2[array_key_last($sealed2)]);
    echo 'open_turn_file_after=' . (is_file($resolved . '/' . Threads::TURN_FILE) ? 'yes' : 'no') . "\n";

    $assistant = '';
    if ($committed !== null) {
        foreach (array_reverse($committed->completions) as $c) {
            if ($c->output instanceof AssistantMessage) {
                $assistant = trim((string) ($c->output->content ?? ''));
                if ($assistant !== '') {
                    break;
                }
            }
        }
    }
    echo "assistant_final={$assistant}\n";

    return $committed?->status === TurnStatus::Completed
        && $committed->interrupt === null
        && $assistant !== ''
        && !is_file($resolved . '/' . Threads::TURN_FILE);
});

echo "\nRESULT: " . ($ok ? 'PASS' : 'FAIL') . "\n";
exit($ok ? 0 : 1);
