#!/usr/bin/env php
<?php

require_once __DIR__ . '/lib/DocProofSupport.php';

use Switon\Skills\DocProofs\DocProofSupport;

[$options] = DocProofSupport::parseArgs($argv);
$root = DocProofSupport::repoRoot();
$currentApp = $root . '/tests/doc-proofs/tutorial-mainline-app';

$proofApp = null;
$proofStatus = 'missing';
$issues = [];

if (is_dir($currentApp)) {
    $proofApp = $currentApp;
    $proofStatus = 'current';
} else {
    $issues[] = 'No learning-mainline proof app found at tests/doc-proofs/tutorial-mainline-app.';
}

$checks = [];
if ($proofApp !== null) {
    foreach ([
        'bash bin/console task:setup --reset',
        'bash bin/console router:list --json',
        'bash bin/console list --json',
        'bash bin/console task:complete 1',
    ] as $command) {
        $checks[] = DocProofSupport::runCommand($command, $proofApp);
    }

    $runtimeDir = $root . '/build/doc-proofs/learning-mainline';
    if (!is_dir($runtimeDir)) {
        if (!mkdir($runtimeDir, 0777, true) && !is_dir($runtimeDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $runtimeDir));
        }
    }

    $serverLog = $runtimeDir . '/server.log';
    $startServer = DocProofSupport::runCommand(
        'sh -c ' . escapeshellarg('SERVER_TYPE=php XDEBUG_MODE=off php public/index.php > ' . escapeshellarg($serverLog) . ' 2>&1 & echo $!'),
        $proofApp,
    );
    $startServer['command'] = 'SERVER_TYPE=php php public/index.php';
    $checks[] = $startServer;

    $serverPid = trim($startServer['stdout'] ?? '');
    $serverReady = false;
    if ($serverPid !== '' && ctype_digit($serverPid)) {
        $serverReady = waitForHttp('127.0.0.1', 9501, 40, 250000);
    }

    if (!$serverReady) {
        $issues[] = 'Learning-mainline HTTP server did not become ready on 127.0.0.1:9501.';
    } else {
        foreach ([
            'curl -s http://127.0.0.1:9501/api/tasks',
            'curl -s -X POST http://127.0.0.1:9501/api/tasks -H \'Content-Type: application/json\' -d \'{"title":"Proof route","note":"via HTTP"}\'',
            'curl -s -X PUT http://127.0.0.1:9501/api/tasks/1/complete',
        ] as $command) {
            $checks[] = DocProofSupport::runCommand($command, $proofApp);
        }
    }

    if ($serverPid !== '' && ctype_digit($serverPid)) {
        $stopServer = DocProofSupport::runCommand('kill ' . $serverPid, $proofApp);
        $stopServer['command'] = 'kill ' . $serverPid;
        $checks[] = $stopServer;
    }
}

$failedChecks = array_values(array_filter(
    $checks,
    static fn (array $check): bool => ($check['exit_code'] ?? 1) !== 0,
));

if ($failedChecks !== []) {
    $issues[] = 'One or more runnable proof checks failed.';
}

$payload = [
    'root' => $root,
    'proof_app_status' => $proofStatus,
    'proof_app_path' => $proofApp,
    'checks' => $checks,
    'issues' => $issues,
];

DocProofSupport::printPayload($payload, $options['json']);

if ($proofStatus !== 'current' || $failedChecks !== []) {
    exit(1);
}

function waitForHttp(string $host, int $port, int $maxAttempts, int $sleepMicros): bool
{
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $connection = @fsockopen($host, $port, $errno, $error, 0.5);
        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }

        usleep($sleepMicros);
    }

    return false;
}
