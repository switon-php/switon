#!/usr/bin/env php
<?php
/**
 * List switon/* require and require-dev per package.
 * Run from repo root. Output: one block per package.
 *
 * Usage: php skills/sps-packages-deps/scripts/list-deps.php
 *        php .../list-deps.php --table   # markdown table
 */

// Prefer cwd (run from repo root); else resolve from script path
$repoRoot = getcwd();
if (!is_dir($repoRoot . '/packages')) {
    $repoRoot = realpath(__DIR__ . '/../../../../') ?: $repoRoot;
}
$packagesDir = $repoRoot . '/packages';
if (!is_dir($packagesDir)) {
    fwrite(STDERR, "packages/ not found under {$repoRoot}. Run from repo root.\n");
    exit(1);
}
$table = in_array('--table', $argv ?? [], true);

$rows = [];
foreach (glob($packagesDir . '/*/composer.json') as $f) {
    $j = json_decode(file_get_contents($f), true);
    $name = $j['name'] ?? basename(dirname($f));
    if (strpos($name, 'switon/') !== 0) {
        continue;
    }
    $req = $j['require'] ?? [];
    $reqDev = $j['require-dev'] ?? [];
    $reqSwiton = array_keys(array_filter($req, fn($_, $k) => strpos((string)$k, 'switon/') === 0, ARRAY_FILTER_USE_BOTH));
    $devSwiton = array_keys(array_filter($reqDev, fn($_, $k) => strpos((string)$k, 'switon/') === 0, ARRAY_FILTER_USE_BOTH));
    sort($reqSwiton);
    sort($devSwiton);
    $rows[] = [
        'name' => $name,
        'require' => array_map(fn($p) => str_replace('switon/', '', $p), $reqSwiton),
        'require-dev' => array_map(fn($p) => str_replace('switon/', '', $p), $devSwiton),
    ];
}
usort($rows, fn($a, $b) => strcmp($a['name'], $b['name']));

if ($table) {
    echo "| 包 | require | require-dev |\n";
    echo "|----|--------|-------------|\n";
    foreach ($rows as $r) {
        $req = implode(', ', $r['require']) ?: '—';
        $dev = implode(', ', $r['require-dev']) ?: '—';
        echo "| {$r['name']} | {$req} | {$dev} |\n";
    }
} else {
    foreach ($rows as $r) {
        echo $r['name'] . "\n";
        echo "  require: " . (implode(', ', $r['require']) ?: '—') . "\n";
        echo "  require-dev: " . (implode(', ', $r['require-dev']) ?: '—') . "\n";
        echo "\n";
    }
}
