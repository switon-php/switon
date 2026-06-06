#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/DocProofSupport.php';

use Switon\Skills\DocProofs\DocProofSupport;

[$options, $targetArgs] = DocProofSupport::parseArgs($argv);
$root = DocProofSupport::repoRoot();
$targets = DocProofSupport::resolveTargets($root, $targetArgs);
$files = DocProofSupport::collectMarkdownFiles($targets);
$assertions = DocProofSupport::extractAssertions($root, $files);
$routes = DocProofSupport::determineRoutes($targets, $assertions);
$routeNames = array_column($routes, 'route');

$checks = [];
$issues = [];
$notes = [];

if (in_array('snippet_validation', $routeNames, true)) {
    $arguments = $targetArgs === [] ? ['learning-docs'] : array_values(array_unique(array_column($targets, 'label')));
    $command = 'php scripts/validate_learning_doc_php_snippets.php';
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }

    $checks[] = DocProofSupport::runCommand($command, $root);
}

if (in_array('link_audit', $routeNames, true)) {
    $tree = DocProofSupport::determineLinkAuditTree($root, $targets);
    $checks[] = DocProofSupport::runCommand(
        'python3 scripts/docs/markdown_link_audit.py --tree ' . escapeshellarg($tree),
        $root,
    );
}

if (in_array('learning_mainline_runtime', $routeNames, true)) {
    $checks[] = DocProofSupport::runCommand(
        'php skills/sps-audit-doc-proofs/scripts/verify_learning_mainline.php --json',
        $root,
    );

    foreach (array_column($targets, 'path') as $targetPath) {
        if (
            str_contains($targetPath, '/docs/en/guides')
            || str_contains($targetPath, '/docs/en/getting-started')
        ) {
            $notes[] = 'Runnable proof currently covers the shared learning-mainline baseline in tests/doc-proofs/tutorial-mainline-app; guide-specific or onboarding-only integrations still need dedicated proof assets when stronger runtime proof is required.';
            break;
        }
    }
}

if (in_array('identifier_review', $routeNames, true)) {
    $issues[] = 'Identifier and behavior review still needs source-backed inspection; use sps-verify-identifiers alongside these automated checks.';
}

$failedChecks = array_values(array_filter(
    $checks,
    static fn (array $check): bool => ($check['exit_code'] ?? 1) !== 0,
));

if ($failedChecks !== []) {
    $issues[] = 'One or more automated doc-proof checks failed.';
}

$payload = [
    'root' => $root,
    'targets' => array_column($targets, 'label'),
    'assertion_counts' => $assertions['assertion_counts'],
    'routes' => $routes,
    'checks' => $checks,
    'notes' => $notes,
    'issues' => $issues,
];

DocProofSupport::printPayload($payload, $options['json']);

if (($options['fail-on-issues'] && $issues !== []) || $failedChecks !== []) {
    exit(1);
}
