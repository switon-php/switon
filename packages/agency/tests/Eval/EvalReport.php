<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Eval;

/** Aggregate eval scenario results. */
class EvalReport
{
    /**
     * @param list<ScenarioResult> $results
     */
    public function __construct(public array $results)
    {
    }

    public function passed(): int
    {
        return count(array_filter($this->results, static fn (ScenarioResult $r): bool => $r->passed));
    }

    public function total(): int
    {
        return count($this->results);
    }

    public function score(): float
    {
        return $this->total() === 0 ? 0.0 : $this->passed() / $this->total();
    }

    public function ok(): bool
    {
        return $this->passed() === $this->total();
    }

    /**
     * @return array{suite: string, passed: int, total: int, score: float, results: list<array<string, mixed>>}
     */
    public function toArray(string $suite): array
    {
        return [
            'suite' => $suite,
            'passed' => $this->passed(),
            'total' => $this->total(),
            'score' => round($this->score(), 4),
            'results' => array_map(static fn (ScenarioResult $r): array => [
                'id' => $r->id,
                'passed' => $r->passed,
                'failures' => $r->failures,
                'assistant' => $r->assistant,
                'metrics' => $r->metrics,
            ], $this->results),
        ];
    }

    public function printSummary(string $suite): void
    {
        echo str_repeat('=', 72) . "\n";
        echo "Eval suite: {$suite}\n";
        foreach ($this->results as $result) {
            $mark = $result->passed ? 'PASS' : 'FAIL';
            echo sprintf("[%s] %s\n", $mark, $result->id);
            if ($result->metrics !== []) {
                echo '  metrics: ' . json_encode($result->metrics, JSON_UNESCAPED_UNICODE) . "\n";
            }
            if (!$result->passed) {
                foreach ($result->failures as $failure) {
                    echo '  - ' . $failure . "\n";
                }
                if ($result->assistant !== '') {
                    echo '  assistant: ' . $result->assistant . "\n";
                }
            }
        }
        echo str_repeat('=', 72) . "\n";
        echo sprintf("Score: %d/%d (%.0f%%)\n", $this->passed(), $this->total(), $this->score() * 100);
    }
}
