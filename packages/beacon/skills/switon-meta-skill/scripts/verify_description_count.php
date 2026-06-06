#!/usr/bin/env php
<?php

/**
 * Verify question and gold counts in verify-description output dir (do not trust generate script alone).
 *
 * Usage:
 *   php scripts/verify_description_count.php <output-dir> [expected_N]
 *
 * Reads questions.json and gold.json from output-dir; prints actual counts.
 * If expected_N is given: exit 0 only when both counts equal expected_N; else exit 1 and report mismatch.
 */
if ($argc < 2 || $argc > 3) {
    fwrite(STDERR, "Usage: php scripts/verify_description_count.php <output-dir> [expected_N]\n");
    exit(1);
}

$outputDir = rtrim($argv[1], DIRECTORY_SEPARATOR);
$expectedN = isset($argv[2]) ? (int)$argv[2] : null;

$questionsPath = $outputDir . DIRECTORY_SEPARATOR . 'questions.json';
$goldPath = $outputDir . DIRECTORY_SEPARATOR . 'gold.json';

if (!is_file($questionsPath)) {
    fwrite(STDERR, "Error: questions.json not found in {$outputDir}\n");
    exit(1);
}
if (!is_file($goldPath)) {
    fwrite(STDERR, "Error: gold.json not found in {$outputDir}\n");
    exit(1);
}

$questionsRaw = file_get_contents($questionsPath);
$goldRaw = file_get_contents($goldPath);
if ($questionsRaw === false || $goldRaw === false) {
    fwrite(STDERR, "Error: failed to read files\n");
    exit(1);
}

$questions = json_decode($questionsRaw, true);
$gold = json_decode($goldRaw, true);
if (!is_array($questions) || array_values($questions) !== $questions) {
    fwrite(STDERR, "Error: questions.json is not a JSON array\n");
    exit(1);
}
if (!is_array($gold) || array_values($gold) !== $gold) {
    fwrite(STDERR, "Error: gold.json is not a JSON array\n");
    exit(1);
}

$nQuestions = count($questions);
$nGold = count($gold);

echo "questions: {$nQuestions}\n";
echo "gold: {$nGold}\n";

if ($expectedN !== null) {
    if ($nQuestions !== $expectedN || $nGold !== $expectedN) {
        fwrite(STDERR, "Mismatch: expected {$expectedN}, got questions {$nQuestions}, gold {$nGold}\n");
        exit(1);
    }
}

exit(0);
