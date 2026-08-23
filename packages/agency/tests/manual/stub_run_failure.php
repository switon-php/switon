<?php

declare(strict_types=1);

/**
 * Stub LLM (no API key): runtime agent-run failure / closeFailedRun scenarios.
 *
 *   php packages/agency/tests/manual/stub_run_failure.php
 *   php packages/agency/tests/manual/stub_run_failure.php host-sync
 *
 * Scenarios:
 *   host-sync           Host run() + LLM throw → interrupted kind exception (FQCN message)
 *   sub-tasking-deliver SubAgent tasking + deliver() worker fails → parent inbox task_finish failed
 *   host-after-tool     Host: tool runs then LLM throw → interrupt + tool_results landed
 */

use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Agency\Agent;
use Switon\Ai\Envelope;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

require __DIR__ . '/live_bootstrap.php';

function liveCreateDelegated(\Switon\Di\Container $container, Agent $host, array $params): Agent
{
    $profileId = trim((string) ($params['profileId'] ?? 'lite'));
    $leaf = $params['threadId'] ?? null;
    if (is_string($leaf) && $leaf !== '' && str_contains($leaf, '.')) {
        [, $leaf] = explode('.', $leaf, 2);
    }
    if (!is_string($leaf) || $leaf === '') {
        $leaf = null;
    }

    return $host->spawn($container->get(\Switon\Agency\ProfilesInterface::class)->get($profileId), $leaf);
}

/** @param callable(): bool $run */
function liveFailureScenario(string $id, string $label, callable $run): bool
{
    echo "\n=== {$id}: {$label} ===\n";
    try {
        $ok = $run();
    } catch (\Throwable $e) {
        echo "UNCAUGHT: {$e->getMessage()}\n";
        $ok = false;
    }
    echo 'RESULT: ' . ($ok ? 'PASS' : 'FAIL') . "\n";

    return $ok;
}

function liveLlmDown(): \RuntimeException
{
    return new \RuntimeException('stub LLM down');
}

function liveClosureKind(\Switon\Ai\Thread $thread): ?string
{
    $closures = liveRuntimeClosureCompletions($thread);
    if ($closures === []) {
        return null;
    }
    $signal = liveSignalInCompletion($closures[array_key_last($closures)]);

    return $signal instanceof \Switon\Ai\Signal\UserAbortSignal
        || $signal instanceof \Switon\Ai\Signal\AssistantAbortSignal
        ? $signal->kind
        : null;
}

function liveParentTaskFinishFailed(\Switon\Ai\Thread $parent, ?string $from = null): ?array
{
    foreach (liveTaskEventsInConversation($parent) as $event) {
        if (($event['signal'] ?? '') !== 'task_finish') {
            continue;
        }
        if (($event['status'] ?? '') !== 'failed') {
            continue;
        }
        if ($from !== null && ($event['thread_id'] ?? '') !== $from) {
            continue;
        }

        return $event;
    }

    return null;
}

function scenarioHostSync(): bool
{
    return Runner::run(function (ContainerInterface $container): bool {
    $stub = new StubLlm();
    $container->set(\Switon\Ai\LlmInterface::class, $stub);
    $container->set(RecordingAgentManager::class, RecordingAgentManager::class);
    $stub->throwOnce = liveLlmDown();
    $host = $container->get(RecordingAgentManager::class)->mint([]);
    liveTrackOpenAgent($host);

    $threw = false;
    try {
        $host->run(Envelope::of('go', liveModel($container, $host->profile()->model)));
    } catch (\RuntimeException) {
        $threw = true;
    }

    $kind = liveClosureKind($host->thread());
    $closure = liveRuntimeClosureCompletions($host->thread());
    $signal = $closure !== [] ? liveSignalInCompletion($closure[array_key_last($closure)]) : null;
    $wire = $signal !== null ? ($signal->wire()[0]['text'] ?? null) : null;
    echo 'run threw: ' . ($threw ? 'yes' : 'no') . "\n";
    echo 'runtime closure kind: ' . ($kind ?? '(none)') . "\n";
    if ($wire !== null) {
        echo "interrupted wire:\n{$wire}\n";
    }

    return $threw && $kind === null;
    });
}

function scenarioSubTaskingDeliver(): bool
{
    return Runner::run(function (ContainerInterface $container): bool {
    liveRegisterStubProfiles($container);
    liveRegisterAgentPackage($container);
    $stub = new StubLlm();
    $container->set(\Switon\Ai\LlmInterface::class, $stub);
    $container->set(RecordingAgentManager::class, RecordingAgentManager::class);
    $stub->throwOnce = liveLlmDown();

    $host = $container->get(RecordingAgentManager::class)->mint([]);
    liveTrackOpenAgent($host);
    /** @var Agent $child */
    $child = liveCreateDelegated($container, $host, [
        'profileId' => 'lite',
        'blueprint' => 'text',
    ]);
    $leaf = pathinfo($child->threadId(), PATHINFO_EXTENSION);
    liveAgentRegisterChild($host, $child);
    liveAgentOpenTask($child, 'test-task');

    liveAgentDeliver($container, $child, UserMessage::of('work'));
    liveDrainDelegations($host, 8.0);

    $parentFinish = liveParentTaskFinishFailed($host->thread(), $leaf);
    $childKind = liveClosureKind($child->thread());
    $stillRegistered = isset(liveAgentChildren($host)[$leaf]);

    echo 'parent inbox task_finish failed: ' . ($parentFinish !== null ? 'yes' : 'no') . "\n";
    echo 'child runtime closure kind: ' . ($childKind ?? '(none)') . "\n";
    echo 'ephemeral killed: ' . ($stillRegistered ? 'no' : 'yes') . "\n";

    return $parentFinish !== null && $childKind === null && !$stillRegistered;
    });
}

function scenarioHostAfterTool(): bool
{
    return Runner::run(function (ContainerInterface $container): bool {
    $stub = new StubLlm();
    $container->set(\Switon\Ai\LlmInterface::class, $stub);
    $container->set(RecordingAgentManager::class, RecordingAgentManager::class);
    liveBindTools($container, [
        'echo' => \Switon\Agency\Tests\Fixtures\StubTool::config('echo', 'Echo', static fn (): string => 'echo-ok'),
    ]);
    $stub->responses[] = StubLlm::assistantWithToolCall('echo', ['text' => 'ping']);
    $stub->responses[] = static function (): never {
        throw new \RuntimeException('stub LLM down');
    };

    $host = $container->get(RecordingAgentManager::class)->mint([]);
    $profile = clone $host->profile();
    $profile->tools = [...$profile->tools, 'echo'];
    liveBindProfileOverride($host, $profile, $container);
    liveTrackOpenAgent($host);

    $threw = false;
    try {
        $host->run(Envelope::of('go', liveModel($container, $host->profile()->model)));
    } catch (\RuntimeException) {
        $threw = true;
    }

    $toolBodies = [];
    foreach ($host->thread()->turns as $_turn) {
        foreach ($_turn->completions as $completion) {
            $input = $completion->input;
            if ($input instanceof ToolMessage) {
                foreach ($input->results as $result) {
                    $toolBodies[] = $result->content;
                }
            }
        }
    }
    $echoOk = false;
    foreach ($toolBodies as $body) {
        if (str_contains($body, 'echo-ok')) {
            $echoOk = true;
            break;
        }
    }
    $openTools = [];
    foreach ($host->thread()->turns as $_turn) {
        foreach ($_turn->completions as $row) {
            foreach ($row->output?->toolCalls ?? [] as $call) {
                $openTools[$call->id] = $call->name;
            }
            if ($row->input instanceof ToolMessage) {
                foreach ($row->input->results as $result) {
                    unset($openTools[$result->id]);
                }
            }
        }
    }
    $openTools = array_values($openTools);
    $kind = liveClosureKind($host->thread());

    echo 'run threw: ' . ($threw ? 'yes' : 'no') . "\n";
    echo 'echo tool result persisted: ' . ($echoOk ? 'yes' : 'no') . "\n";
    echo 'last completion open tool_calls: ' . count($openTools) . "\n";
    echo 'runtime closure kind: ' . ($kind ?? '(none)') . "\n";

    return $threw && $kind === null && $openTools === [] && $echoOk;
    });
}

/** @return array<string, callable(): bool> */
function liveRunFailureScenarios(): array
{
    return [
        'host-sync' => static fn (): bool => scenarioHostSync(),
        'sub-tasking-deliver' => static fn (): bool => scenarioSubTaskingDeliver(),
        'host-after-tool' => static fn (): bool => scenarioHostAfterTool(),
    ];
}

$scenarios = liveRunFailureScenarios();
$only = $argv[1] ?? null;
if ($only !== null && !isset($scenarios[$only])) {
    fwrite(STDERR, "Unknown scenario \"{$only}\". Choose: " . implode(', ', array_keys($scenarios)) . "\n");
    exit(2);
}

$run = $only !== null ? [$only => $scenarios[$only]] : $scenarios;
$passed = 0;
$failed = 0;
foreach ($run as $id => $fn) {
    if (liveFailureScenario($id, $id, $fn)) {
        $passed++;
    } else {
        $failed++;
    }
}

echo "\n--- summary ---\n";
echo "pass: {$passed}, fail: {$failed}\n";
exit($failed > 0 ? 1 : 0);
