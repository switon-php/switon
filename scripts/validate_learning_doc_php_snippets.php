<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/LearningDocSnippetValidator.php';

use Switon\Scripts\Support\LearningDocSnippetValidator;

$root = dirname(__DIR__);
$validator = new LearningDocSnippetValidator($root);
$args = array_slice($argv, 1);

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    printUsage($validator);
    exit(0);
}

if (in_array('--list-targets', $args, true)) {
    printLogicalTargets($validator);
    exit(0);
}

try {
    $targets = $validator->resolveTargets($args);
    $result = $validator->validateTargets($targets);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

printSummary($result, $validator);

exit(hasFailures($result) ? 2 : 0);

function printUsage(LearningDocSnippetValidator $validator): void
{
    $logicalTargets = implode(', ', $validator->logicalTargetNames());

    echo <<<TEXT
Usage:
  php scripts/validate_learning_doc_php_snippets.php [target ...]

Targets:
  - logical targets: {$logicalTargets}, learning-docs
  - file path: docs/en/tutorial/04-data.md
  - directory path: docs/en/tutorial

Options:
  --help, -h       Show this help message
  --list-targets   Show logical targets only

Default target:
  learning-docs
TEXT;
    echo "\n";
}

function printLogicalTargets(LearningDocSnippetValidator $validator): void
{
    foreach ($validator->logicalTargetNames() as $target) {
        echo $target . "\n";
    }

    echo "learning-docs\n";
}

function printSummary(array $result, LearningDocSnippetValidator $validator): void
{
    printf(
        "Checked %d PHP snippets across %d Markdown files in %d targets.\n",
        $result['totalSnippets'],
        count($result['files']),
        count($result['targets']),
    );
    printf("Targets: %s\n", implode(', ', array_column($result['targets'], 'label')));
    printf("OK: %d\n", count($result['okSnippets']));
    printf("Syntax failures: %d\n", count($result['syntaxFailures']));
    printf("Symbol failures: %d\n", count($result['symbolFailures']));
    printf("Method failures: %d\n", count($result['methodFailures']));

    $validator->printFailures('Syntax failures', $result['syntaxFailures']);
    $validator->printFailures('Symbol failures', $result['symbolFailures']);
    $validator->printFailures('Method failures', $result['methodFailures']);
}

function hasFailures(array $result): bool
{
    return $result['syntaxFailures'] !== []
        || $result['symbolFailures'] !== []
        || $result['methodFailures'] !== [];
}
