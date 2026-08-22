#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * List switon/* dependencies in split-order waves and fail when they form a cycle.
 *
 *   php scripts/package-deps.php
 *   php scripts/package-deps.php --dev
 *
 * Default graph is composer require. --dev uses require and require-dev.
 */

$withDev = in_array('--dev', $argv, true);
$root = dirname(__DIR__);
$graph = [];

foreach (glob($root . '/packages/*/composer.json') ?: [] as $file) {
    $dir = basename(dirname($file));
    $composer = json_decode((string) file_get_contents($file), true);
    if (!is_array($composer)) {
        fwrite(STDERR, "package-deps: invalid composer.json in {$dir}\n");
        exit(1);
    }

    $deps = [];
    foreach (['require', ...($withDev ? ['require-dev'] : [])] as $section) {
        foreach (array_keys((array) ($composer[$section] ?? [])) as $name) {
            if (str_starts_with($name, 'switon/')) {
                $deps[] = substr($name, strlen('switon/'));
            }
        }
    }
    $graph[$dir] = array_values(array_unique($deps));
}

ksort($graph);

foreach ($graph as $package => $deps) {
    echo $package . ($deps === [] ? "\n" : ' ' . implode(' ', $deps) . "\n");
}

$remaining = array_keys($graph);
$wave = 0;
while ($remaining !== []) {
    $ready = array_values(array_filter(
        $remaining,
        static fn (string $package): bool => array_intersect($graph[$package], $remaining) === [],
    ));
    if ($ready === []) {
        fwrite(STDERR, "\npackage-deps: cycle in " . implode(' ', $remaining) . "\n");
        exit(1);
    }
    sort($ready);
    $wave++;
    echo "\n# {$wave}\n" . implode(' ', $ready) . "\n";
    $remaining = array_values(array_diff($remaining, $ready));
}

echo "\nno cycle\n";
