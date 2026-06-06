#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/check-deps.php';

function buildPackageDependencyGraph(string $repoRoot, bool $includeTests = false): array
{
    $nsToPkg = switonDependencyNamespaceMap();
    $dirToNs = array_flip($nsToPkg);
    $graph = [];

    foreach (array_filter(glob($repoRoot . '/packages/*'), 'is_dir') as $packageDir) {
        $pkgDir = basename($packageDir);

        if (!isset($dirToNs[$pkgDir])) {
            continue;
        }

        $composerPath = $packageDir . '/composer.json';
        $composer = json_decode((string)file_get_contents($composerPath), true);
        if (!is_array($composer)) {
            continue;
        }

        $packageName = (string)($composer['name'] ?? ('switon/' . $pkgDir));
        if (!str_starts_with($packageName, 'switon/')) {
            continue;
        }

        $ownNs = $dirToNs[$pkgDir];
        $deps = scanDependencyDirectory($packageDir . '/src', $ownNs, $nsToPkg);

        foreach (($composer['autoload']['files'] ?? []) as $relativePath) {
            foreach (collectSwitonDependenciesFromFile($packageDir . '/' . $relativePath, $ownNs, $nsToPkg) as $dep => $_) {
                $deps[$dep] = true;
            }
        }

        if ($includeTests) {
            foreach (scanDependencyDirectory($packageDir . '/tests', $ownNs, $nsToPkg) as $dep => $_) {
                $deps[$dep] = true;
            }
        }

        $graph[$packageName] = array_keys($deps);
        sort($graph[$packageName]);
    }

    ksort($graph);

    return $graph;
}

function buildReversePackageDependencyGraph(array $graph): array
{
    $reverse = [];

    foreach ($graph as $from => $tos) {
        foreach ($tos as $to) {
            $reverse[$to][] = $from;
        }
    }

    foreach ($reverse as $pkg => $froms) {
        $reverse[$pkg] = array_values(array_unique($froms));
        sort($reverse[$pkg]);
    }

    ksort($reverse);

    return $reverse;
}

function renderPackageDependencyGraphSummary(array $graph): string
{
    $reverse = buildReversePackageDependencyGraph($graph);
    $outCounts = [];
    $inCounts = [];
    $edges = 0;

    foreach ($graph as $pkg => $deps) {
        $outCounts[$pkg] = count($deps);
        $edges += count($deps);
        foreach ($deps as $dep) {
            $inCounts[$dep] = ($inCounts[$dep] ?? 0) + 1;
        }
    }

    $lines = [];
    $lines[] = 'packages=' . count($graph) . ' edges=' . $edges;
    $lines[] = '';
    $lines[] = 'top outgoing:';
    foreach (topPackageCounts($outCounts, 10) as [$pkg, $count]) {
        $lines[] = '  ' . $pkg . ' (' . $count . '): ' . implode(', ', $graph[$pkg]);
    }
    $lines[] = '';
    $lines[] = 'top incoming:';
    foreach (topPackageCounts($inCounts, 10) as [$pkg, $count]) {
        $lines[] = '  ' . $pkg . ' (' . $count . '): ' . implode(', ', $reverse[$pkg] ?? []);
    }

    return implode("\n", $lines) . "\n";
}

function renderPackageDependencyGraphTable(array $graph): string
{
    $reverse = buildReversePackageDependencyGraph($graph);
    $rows = [];

    foreach ($graph as $pkg => $deps) {
        $rows[] = [
            'package' => $pkg,
            'out' => count($deps),
            'in' => count($reverse[$pkg] ?? []),
            'deps' => implode(', ', $deps) ?: '—',
            'used-by' => implode(', ', $reverse[$pkg] ?? []) ?: '—',
        ];
    }

    usort($rows, static fn(array $a, array $b): int => [$b['out'], $b['in'], $a['package']] <=> [$a['out'], $a['in'], $b['package']]);

    $lines = [];
    $lines[] = '| package | out | in | deps | used-by |';
    $lines[] = '|----|----:|----:|------|---------|';
    foreach ($rows as $row) {
        $lines[] = '| ' . $row['package'] . ' | ' . $row['out'] . ' | ' . $row['in'] . ' | ' . $row['deps'] . ' | ' . $row['used-by'] . ' |';
    }

    return implode("\n", $lines) . "\n";
}

function topPackageCounts(array $counts, int $limit): array
{
    arsort($counts);
    $counts = array_slice($counts, 0, $limit, true);

    $rows = [];
    foreach ($counts as $pkg => $count) {
        $rows[] = [$pkg, $count];
    }

    return $rows;
}

function detectGraphDependencyRepoRoot(string $cwd): ?string
{
    if (is_dir($cwd . '/packages')) {
        return $cwd;
    }

    $fallback = realpath(__DIR__ . '/../../../../');

    if ($fallback !== false && is_dir($fallback . '/packages')) {
        return $fallback;
    }

    return null;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $repoRoot = detectGraphDependencyRepoRoot(getcwd());

    if ($repoRoot === null) {
        fwrite(STDERR, "packages/ not found. Run from repo root.\n");
        exit(1);
    }

    $includeTests = in_array('--include-tests', $argv ?? [], true);
    $table = in_array('--table', $argv ?? [], true);

    echo $table
        ? renderPackageDependencyGraphTable(buildPackageDependencyGraph($repoRoot, $includeTests))
        : renderPackageDependencyGraphSummary(buildPackageDependencyGraph($repoRoot, $includeTests));
}
