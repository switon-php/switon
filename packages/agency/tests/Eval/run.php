<?php

declare(strict_types=1);

/**
 * Agent eval harness CLI.
 *
 *   php packages/agency/tests/eval/run.php skills
 *   php packages/agency/tests/eval/run.php guessing [max_runs]
 *   php packages/agency/tests/eval/run.php skills --json
 *
 * Live suites use ~/.switon/llm.yml (see packages/llm/resources/llm.yml.example). Exits 1 when score < 100%.
 */

use Switon\Agency\Tests\Eval\EvalReport;
use Switon\Agency\Tests\Eval\Scenarios\SkillsScenarios;
use Switon\Agency\Tests\Eval\GuessingGameEvalRunner;
use Switon\Agency\Tests\Eval\Scenarios\GuessingGameScenarios;
use Switon\Agency\Tests\Eval\SkillsEvalRunner;
use Switon\Llm\Exception\ConfigurationException;
use Switon\Llm\LlmProvidersInterface;
use Switon\Cli\Runner;
use Switon\Di\ContainerInterface;

require dirname(__DIR__) . '/manual/live_bootstrap.php';

/** @param list<string> $argv */
function evalArgvFlags(array $argv): bool
{
    return in_array('--json', $argv, true);
}

/** @param list<string> $argv */
function evalRunSkills(): array
{
    return Runner::run(function (ContainerInterface $container): array {
    liveBindTools($container, ['read', 'grep']);
    liveAppendSkillPaths($container, [dirname(__DIR__) . '/manual/skills']);
    liveRegisterAgentPackage($container);
    liveStartConsole($container);

    $runner = new SkillsEvalRunner($container);
    $results = [];
    foreach (SkillsScenarios::liveDemo() as $scenario) {
        $results[] = $runner->run($scenario);
    }

    return $results;
    });
}

/** @param list<string> $argv */
function evalRunGuessing(array $argv): array
{
    $scenario = GuessingGameScenarios::defaultGame();
    if (isset($argv[2]) && is_numeric($argv[2])) {
        $scenario['max_runs'] = max(2, (int) $argv[2]);
    }

    return Runner::run(function (ContainerInterface $container) use ($scenario): array {
        liveBindTools($container, []);
        liveRegisterAgentPackage($container);
        liveStartConsole($container);
        if (liveBindLangfuse($container)) {
            echo "\n";
        }

        echo "Guessing game: {$scenario['max_runs']} runs\n\n";

        return [(new GuessingGameEvalRunner($container))->run($scenario)];
    });
}

$suite = $argv[1] ?? 'skills';
$json = evalArgvFlags($argv);

try {
    Runner::run(function (ContainerInterface $container): void {
        $container->get(LlmProvidersInterface::class)->get('main');
    });
} catch (ConfigurationException|\Throwable $e) {
    fwrite(STDERR, "Eval requires ~/.switon/llm.yml: {$e->getMessage()}\n");
    fwrite(STDERR, "See packages/llm/resources/llm.yml.example\n");
    exit(2);
}

$results = match ($suite) {
    'skills' => evalRunSkills(),
    'guessing', 'trim-guessing' => evalRunGuessing($argv),
    default => (static function () use ($suite): array {
        fwrite(STDERR, "Unknown suite \"{$suite}\". Use: skills, guessing\n");
        exit(2);
    })(),
};

$report = new EvalReport($results);

if ($json) {
    echo json_encode($report->toArray($suite), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    $report->printSummary($suite);
}

exit($report->ok() ? 0 : 1);
