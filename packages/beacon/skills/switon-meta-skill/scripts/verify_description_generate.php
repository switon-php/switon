#!/usr/bin/env php
<?php

/**
 * Generate questions.json and gold.json for verify-description (description test).
 *
 * Usage:
 *   php scripts/verify_description_generate.php references_dir limit
 *
 * - references_dir: directory containing `*-questions.md` files with Q/A pairs
 * - limit: max number of items to sample from source (after shuffle)
 *
 * Output directory: sys_get_temp_dir() . '/verify-description' (e.g. /tmp/verify-description). Script prints path on line 1 and question count N on line 2.
 */
if ($argc !== 3) {
    fwrite(STDERR, "Usage: php scripts/verify_description_generate.php references_dir limit\n");
    exit(1);
}

[$script, $sourcePath, $limitRaw] = $argv;
$limit = (int)$limitRaw;
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'verify-description';

/**
 * Load all {question, answer} items from `*-questions.md` files in a directory.
 *
 * Expected format inside markdown (coding-style question lists):
 *
 *   1. Question text
 *   2. Another question
 *
 * The answer is implied by the file name:
 *   <topic>-questions.md => "switon-php-coding: <topic>"
 *
 * @param string $dir Directory path
 *
 * @return array<int, array{question:string,answer:string}>
 */
function loadSourceItems(string $dir): array
{
    if (!is_dir($dir)) {
        throw new RuntimeException("references_dir is not a directory: {$dir}");
    }

    $pattern = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*-questions.md';
    $files = glob($pattern) ?: [];
    sort($files);

    $items = [];

    foreach ($files as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }

        $base = basename($file);
        // e.g. "router-questions.md" => "router"
        $topic = preg_replace('/-questions\.md$/', '', $base) ?: '';
        $topic = trim((string)$topic);
        if ($topic === '') {
            continue;
        }
        $answer = 'switon-php-coding: ' . $topic;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            // Match numbered list: "1. Question text"
            if (preg_match('/^\d+\.\s*(.+)$/u', $trimmed, $m)) {
                $q = trim($m[1]);
                if ($q !== '') {
                    $items[] = [
                            'question' => $q,
                            'answer' => $answer,
                    ];
                }
            }
        }
    }

    return $items;
}

try {
    $filtered = loadSourceItems($sourcePath);

    if (empty($filtered)) {
        throw new RuntimeException('No valid {question,answer} items found in source.');
    }

    // Shuffle, then take first limit
    shuffle($filtered);
    $filtered = array_slice($filtered, 0, $limit);

    $questions = [];
    $gold = [];

    foreach ($filtered as $item) {
        $questions[] = $item['question'];
        $gold[] = [$item['answer']];
    }

    // Ensure tmpDir exists
    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
        throw new RuntimeException("Failed to create tmp_dir: {$tmpDir}");
    }

    $questionsPath = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'questions.json';
    $goldPath = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'gold.json';

    $questionsJson = json_encode($questions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $goldJson = json_encode($gold, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if ($questionsJson === false || $goldJson === false) {
        throw new RuntimeException('Failed to encode JSON output.');
    }

    if (file_put_contents($questionsPath, $questionsJson . PHP_EOL) === false) {
        throw new RuntimeException("Failed to write questions.json to {$questionsPath}");
    }

    if (file_put_contents($goldPath, $goldJson . PHP_EOL) === false) {
        throw new RuntimeException("Failed to write gold.json to {$goldPath}");
    }

    $n = count($questions);
    echo $tmpDir . PHP_EOL;
    echo $n . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
