#!/usr/bin/env php
<?php

/**
 * Grade answers for verify-description (description test).
 *
 * Usage:
 *   php scripts/verify_description_grade.php <output-dir>
 *
 * Reads from output-dir: gold.json, answers.json, questions.json (convention from generate + answering agent).
 * When lengths differ: grade the overlapping segment; report length mismatch.
 * Output: total (graded count); hits (strict); hit rate; two dimensions (1 ref, 1–2 refs), both with denominator = total so 1–2 refs rate >= 1 ref rate.
 * When there are strict misses: one-line miss summary (expected → got with counts), then per-miss lines with question text.
 * Exit code: 0 if all graded items are strict hits and lengths match; 1 if any strict miss or length mismatch.
 */
if ($argc !== 2) {
    fwrite(STDERR, "Usage: php scripts/verify_description_grade.php <output-dir>\n");
    exit(1);
}

$outputDir = rtrim($argv[1], DIRECTORY_SEPARATOR);
$goldPath = $outputDir . DIRECTORY_SEPARATOR . 'gold.json';
$answersPath = $outputDir . DIRECTORY_SEPARATOR . 'answers.json';
$questionsPath = $outputDir . DIRECTORY_SEPARATOR . 'questions.json';

function readJsonFile(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("File not found: {$path}");
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Failed to read file: {$path}");
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON in {$path}");
    }

    return $data;
}

try {
    $gold = readJsonFile($goldPath);
    $answers = readJsonFile($answersPath);

    if (array_values($gold) !== $gold || array_values($answers) !== $answers) {
        throw new RuntimeException('gold.json and answers.json must both be JSON arrays.');
    }

    $goldCount = count($gold);
    $answersCount = count($answers);
    $total = min($goldCount, $answersCount);

    if ($total === 0) {
        echo "Total: 0\nHits: 0\nHit rate: 0.00%\n";
        if ($goldCount !== $answersCount) {
            echo "Length mismatch: gold {$goldCount}, answers {$answersCount}\n";
        }
        exit($goldCount !== $answersCount ? 1 : 0);
    }

    if ($goldCount !== $answersCount) {
        echo "Length mismatch: gold {$goldCount}, answers {$answersCount}; grading first {$total} items.\n";
    }

    $hitsStrict = 0;
    $misses = [];
    $dim1Total = 0;
    $dim1Hits = 0;
    $dim2Total = 0;
    $dim2Hits = 0;

    for ($i = 0; $i < $total; $i++) {
        $expectedRow = $gold[$i];
        $answerRow = $answers[$i] ?? [];

        if (!is_array($expectedRow) || !is_array($answerRow)) {
            continue;
        }

        $expectedSet = [];
        foreach ($expectedRow as $s) {
            $s = trim((string)$s);
            if ($s !== '') {
                $expectedSet[$s] = true;
            }
        }

        $answerSet = [];
        $answerList = [];
        foreach ($answerRow as $s) {
            $s = trim((string)$s);
            if ($s !== '') {
                $answerSet[$s] = true;
                $answerList[] = $s;
            }
        }

        if (empty($expectedSet)) {
            continue;
        }

        $allPresent = true;
        foreach ($expectedSet as $s => $_) {
            if (!isset($answerSet[$s])) {
                $allPresent = false;
                break;
            }
        }

        $atMostTwo = count($answerRow) <= 2;
        $numRefs = count($answerList);
        $anyInGold = false;
        foreach ($answerList as $ref) {
            if (isset($expectedSet[$ref])) {
                $anyInGold = true;
                break;
            }
        }

        if ($allPresent && $atMostTwo) {
            $hitsStrict++;
        } else {
            $misses[] = [
                    'i' => $i,
                    'expected' => $expectedRow,
                    'got' => $answerRow,
            ];
        }

        if ($numRefs === 1) {
            $dim1Total++;
            if ($anyInGold) {
                $dim1Hits++;
            }
        }
        if ($numRefs >= 1 && $numRefs <= 2) {
            $dim2Total++;
            if ($anyInGold) {
                $dim2Hits++;
            }
        }
    }

    $hitRateStrict = $total > 0 ? ($hitsStrict / $total) * 100.0 : 0.0;

    echo "Total: {$total}\n";
    echo "Hits (strict): {$hitsStrict}\n";
    echo "Hit rate (strict): " . number_format($hitRateStrict, 2) . "%\n";
    echo "By dimension (hit = at least one ref in gold; denominator = graded total {$total}):\n";
    echo "  1 ref:    " . ($total > 0 ? number_format(($dim1Hits / $total) * 100.0, 2) . "% ({$dim1Hits}/{$total})" : "N/A") . "\n";
    echo "  1–2 refs: " . ($total > 0 ? number_format(($dim2Hits / $total) * 100.0, 2) . "% ({$dim2Hits}/{$total})" : "N/A") . "\n";

    if ($misses !== []) {
        $summary = [];
        foreach ($misses as $m) {
            $exp = $m['expected'];
            $got = $m['got'];
            $expStr = is_array($exp) && ($firstExpKey = array_key_first($exp)) !== null ? ((string)($exp[$firstExpKey] ?? '')) : (string)$exp;
            $gotStr = is_array($got) && ($firstGotKey = array_key_first($got)) !== null ? ((string)($got[$firstGotKey] ?? '')) : (string)$got;
            $key = $expStr . ' → ' . $gotStr;
            $summary[$key] = ($summary[$key] ?? 0) + 1;
        }
        arsort($summary, SORT_NUMERIC);
        echo "Miss summary: " . count($misses) . " misses. ";
        $parts = [];
        foreach ($summary as $key => $n) {
            $parts[] = "{$key} ({$n})";
        }
        echo implode(', ', $parts) . "\n";

        $questions = null;
        if (is_file($questionsPath)) {
            $questions = readJsonFile($questionsPath);
            if (!is_array($questions)) {
                $questions = null;
            }
        }
        echo "Misses:\n";
        foreach ($misses as $m) {
            $i = $m['i'];
            $q = null;
            if ($questions !== null && isset($questions[$i])) {
                $q = is_string($questions[$i]) ? $questions[$i] : json_encode($questions[$i]);
            }
            echo "  [{$i}] ";
            if ($q !== null) {
                echo "Q: " . trim($q) . "\n  [{$i}] ";
            }
            echo "expected: " . json_encode($m['expected']) . " got: " . json_encode($m['got']) . "\n";
        }
    }

    $lengthMismatch = $goldCount !== $answersCount;
    if ($misses !== [] || $lengthMismatch) {
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
