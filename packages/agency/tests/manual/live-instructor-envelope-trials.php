<?php

declare(strict_types=1);

/**
 * Instruct envelope live trials: success (ok) and failure (non-ok) against CC-configured LLM.
 *
 *   php packages/agency/tests/manual/live-instructor-envelope-trials.php
 *   php packages/agency/tests/manual/live-instructor-envelope-trials.php 10
 *
 * Arg is trials per case (ok + fail). Default 5 each → 10 runs.
 */

use Switon\Ai\InstructorInterface;
use Switon\Agency\ProfilesInterface;
use Switon\Llm\Exception\ApiFailedException;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

require __DIR__ . '/live_bootstrap.php';

$perCase = isset($argv[1]) && ctype_digit($argv[1]) ? max(1, (int) $argv[1]) : 5;
Runner::run(function (ContainerInterface $container) use ($perCase): void {
liveBindTools($container, []);
liveStartConsole($container);

$cases = [
    'ok' => [
        'ask' => 'What is 41 + 1?',
        'properties' => [
            'sum' => [
                'type' => 'integer',
                'description' => 'Numeric sum.',
            ],
        ],
        'check' => static function (mixed $outcome): bool {
            return $outcome === ['sum' => 42];
        },
    ],
    'fail' => [
        'ask' => 'Return the absolute path of the input file. No path was provided.',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'Absolute filesystem path.',
            ],
        ],
        'check' => static function (mixed $outcome): bool {
            return $outcome instanceof ApiFailedException
                && $outcome->getMessage() !== '';
        },
    ],
];

$stats = [];
foreach ($cases as $kind => $_) {
    $stats[$kind] = ['pass' => 0, 'fail' => 0];
}

foreach ($cases as $kind => $case) {
    for ($i = 1; $i <= $perCase; $i++) {
        /** @var InstructorInterface $instructor */
        $instructor = $container->make(InstructorInterface::class);
        $profile = $container->get(ProfilesInterface::class)->get('instructor');

        $label = "[{$kind} {$i}/{$perCase}]";
        try {
            $result = liveRunInCoroutine(
                function () use ($instructor, $profile, $case): array {
                    return $instructor->ask($profile, $case['ask'], $case['properties']);
                },
            );
            $outcome = $result;
            $detail = json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            $outcome = $e;
            $detail = $e::class . ': ' . $e->getMessage();
            if ($e instanceof ApiFailedException) {
                $detail .= ' reason=' . $e->reason;
            }
        }

        if ($case['check']($outcome)) {
            $stats[$kind]['pass']++;
            echo "{$label} PASS {$detail}\n";
        } else {
            $stats[$kind]['fail']++;
            echo "{$label} FAIL {$detail}\n";
        }
    }
}

echo "\n";
$totalPass = 0;
$total = 0;
foreach ($stats as $kind => $row) {
    $n = $row['pass'] + $row['fail'];
    $totalPass += $row['pass'];
    $total += $n;
    $rate = $n > 0 ? round(100 * $row['pass'] / $n, 1) : 0.0;
    echo strtoupper($kind) . ": {$row['pass']}/{$n} ({$rate}%)\n";
}
$overall = $total > 0 ? round(100 * $totalPass / $total, 1) : 0.0;
echo "RESULT: {$totalPass}/{$total} ({$overall}%)\n";
exit($totalPass === $total ? 0 : 1);
});
