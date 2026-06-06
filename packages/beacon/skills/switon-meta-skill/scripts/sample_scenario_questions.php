#!/usr/bin/env php
<?php

/**
 * Randomly sample scenario questions from a *-questions.md file (category.sub format).
 *
 * Usage:
 *   php scripts/sample_scenario_questions.php <questions.md> <output.md> [count]
 *
 * - questions.md: path to a *-questions.md (e.g. config-questions.md)
 * - output.md: path for the generated sample file
 * - count: number of questions to sample (default 30)
 *
 * Parses lines matching "N.M. question text" (category.sub). Prints output path (line 1) and actual count N (line 2).
 */
if ($argc < 3 || $argc > 4) {
    fwrite(STDERR, "Usage: php scripts/sample_scenario_questions.php <questions.md> <output.md> [count]\n");
    exit(1);
}

$questionsFile = $argv[1];
$outputFile = $argv[2];
$count = isset($argv[3]) ? (int)$argv[3] : 30;

if (!is_file($questionsFile)) {
    fwrite(STDERR, "Error: not a file: {$questionsFile}\n");
    exit(1);
}

$lines = file($questionsFile, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "Error: failed to read {$questionsFile}\n");
    exit(1);
}

$items = [];
foreach ($lines as $line) {
    $trimmed = trim($line);
    // category.sub. question text
    if (preg_match('/^(\d+\.\d+)\.\s+(.+)$/u', $trimmed, $m)) {
        $items[] = $trimmed;
    }
}

if (empty($items)) {
    fwrite(STDERR, "Error: no category.sub questions found in {$questionsFile}\n");
    exit(1);
}

shuffle($items);
$sampled = array_slice($items, 0, $count);
$n = count($sampled);

$out = "# Scenario questions — sample of {$n}\n\n---\n\n" . implode("\n", $sampled) . "\n";

$dir = dirname($outputFile);
if ($dir !== '' && !is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
    fwrite(STDERR, "Error: failed to create directory for {$outputFile}\n");
    exit(1);
}
if (file_put_contents($outputFile, $out) === false) {
    fwrite(STDERR, "Error: failed to write {$outputFile}\n");
    exit(1);
}
echo $outputFile . "\n";
echo $n . "\n";
