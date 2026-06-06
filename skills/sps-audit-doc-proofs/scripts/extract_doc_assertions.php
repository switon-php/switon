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

$payload = [
    'root' => $root,
    'targets' => array_column($targets, 'label'),
    'files_scanned' => $assertions['files_scanned'],
    'assertion_counts' => $assertions['assertion_counts'],
    'samples' => $assertions['samples'],
    'by_file' => $assertions['by_file'],
];

DocProofSupport::printPayload($payload, $options['json']);
