#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Compare switon/* dependencies against actual code references.
 *
 * Run from repo root. Scans src/, tests/, and autoload files.
 * Ignores comments and strings, but recognizes both imported names and
 * fully-qualified \Switon\... references in executable code.
 */

function switonDependencyNamespaceMap(): array
{
    return [
        'Authorizing' => 'authz', 'Caching' => 'cache', 'Captcha' => 'captcha', 'Cli' => 'cli',
        'ComposerPlugin' => 'composer-plugin', 'Context' => 'context', 'Core' => 'core',
        'Crypto' => 'crypto', 'Db' => 'db', 'Di' => 'di', 'Eventing' => 'event',
        'Http' => 'http', 'HttpCache' => 'http-cache', 'HttpClient' => 'http-client',
        'Id' => 'id', 'I18n' => 'i18n', 'Inspector' => 'inspector',
        'Invoking' => 'invoker', 'Jwt' => 'jwt', 'Kernel' => 'kernel', 'Logging' => 'log',
        'Orm' => 'orm', 'Pooling' => 'pool', 'Query' => 'query', 'Rbac' => 'rbac',
        'Redis' => 'redis', 'Rendering' => 'renderer', 'Routing' => 'router',
        'Session' => 'session', 'Sharding' => 'sharding', 'Sync' => 'sync',
        'Testing' => 'testing', 'Throttle' => 'throttle', 'Validating' => 'validation',
        'Viewing' => 'view',
    ];
}

function detectDependencyRepoRoot(string $cwd): ?string
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

function collectSwitonDependenciesFromCode(string $code, ?string $ownNs, array $nsToPkg): array
{
    $deps = [];
    $tokens = token_get_all($code, TOKEN_PARSE);
    $nameTokenIds = [];

    foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $tokenName) {
        if (defined($tokenName)) {
            $nameTokenIds[] = constant($tokenName);
        }
    }

    foreach ($tokens as $token) {
        if (!is_array($token)) {
            continue;
        }

        [$id, $text] = $token;

        if (!in_array($id, $nameTokenIds, true)) {
            continue;
        }

        if (!preg_match_all('/(?:^|\\\\)Switon\\\\([A-Za-z0-9]+)\\\\/', $text, $matches)) {
            continue;
        }

        foreach ($matches[1] as $ns) {
            if (!isset($nsToPkg[$ns]) || $ns === $ownNs) {
                continue;
            }

            $deps['switon/' . $nsToPkg[$ns]] = true;
        }
    }

    return $deps;
}

function collectSwitonDependenciesFromFile(string $path, ?string $ownNs, array $nsToPkg): array
{
    if (!is_file($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'php') {
        return [];
    }

    $code = file_get_contents($path);

    if ($code === false) {
        return [];
    }

    return collectSwitonDependenciesFromCode($code, $ownNs, $nsToPkg);
}

function scanDependencyDirectory(string $path, ?string $ownNs, array $nsToPkg): array
{
    if (!is_dir($path)) {
        return [];
    }

    $deps = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        foreach (collectSwitonDependenciesFromFile($file->getPathname(), $ownNs, $nsToPkg) as $dep => $_) {
            $deps[$dep] = true;
        }
    }

    return $deps;
}

function readPackageSwitonRequirements(string $composerPath): array
{
    $requirements = ['require' => [], 'require-dev' => [], 'autoload-files' => []];

    if (!is_file($composerPath)) {
        return $requirements;
    }

    $composer = json_decode((string)file_get_contents($composerPath), true);

    if (!is_array($composer)) {
        return $requirements;
    }

    foreach (['require', 'require-dev'] as $section) {
        foreach (($composer[$section] ?? []) as $dep => $version) {
            if (str_starts_with((string)$dep, 'switon/')) {
                $requirements[$section][(string)$dep] = (string)$version;
            }
        }
    }

    $requirements['autoload-files'] = array_merge(
        $composer['autoload']['files'] ?? [],
        $composer['autoload-dev']['files'] ?? [],
    );

    return $requirements;
}

function buildDependencyReport(string $repoRoot): array
{
    $nsToPkg = switonDependencyNamespaceMap();
    $dirToNs = array_flip($nsToPkg);
    $report = [];

    foreach (array_filter(glob($repoRoot . '/packages/*'), 'is_dir') as $packageDir) {
        $pkg = basename($packageDir);

        if (!isset($dirToNs[$pkg]) && $pkg !== 'standards') {
            continue;
        }

        $ownNs = $dirToNs[$pkg] ?? null;
        $srcDeps = scanDependencyDirectory($packageDir . '/src', $ownNs, $nsToPkg);
        $testDeps = scanDependencyDirectory($packageDir . '/tests', $ownNs, $nsToPkg);

        $composerPath = $packageDir . '/composer.json';
        $requirements = readPackageSwitonRequirements($composerPath);

        foreach ($requirements['autoload-files'] as $relativePath) {
            $absolutePath = $packageDir . '/' . $relativePath;

            foreach (collectSwitonDependenciesFromFile($absolutePath, $ownNs, $nsToPkg) as $dep => $_) {
                $srcDeps[$dep] = true;
            }
        }

        $expectedReq = array_keys($srcDeps);
        $expectedDev = array_keys($testDeps);
        $currentReq = array_keys($requirements['require']);
        $currentDev = array_keys($requirements['require-dev']);

        sort($expectedReq);
        sort($expectedDev);
        sort($currentReq);
        sort($currentDev);

        $missingReq = array_values(array_diff($expectedReq, $currentReq));
        $extraReq = array_values(array_diff($currentReq, $expectedReq));
        $missingDev = array_values(array_diff($expectedDev, $currentReq, $currentDev));
        $extraDev = array_values(array_diff($currentDev, $expectedDev, ['switon/testing']));

        if ($missingReq === [] && $extraReq === [] && $missingDev === [] && $extraDev === []) {
            continue;
        }

        $report[$pkg] = [
            'missingReq' => $missingReq,
            'extraReq' => $extraReq,
            'missingDev' => $missingDev,
            'extraDev' => $extraDev,
        ];
    }

    ksort($report);

    return $report;
}

function renderDependencyReport(array $report): string
{
    $output = '';

    foreach ($report as $pkg => $issues) {
        $output .= "=== {$pkg} ===\n";

        if ($issues['missingReq'] !== []) {
            $output .= '  require MISSING: ' . implode(', ', $issues['missingReq']) . "\n";
        }
        if ($issues['extraReq'] !== []) {
            $output .= '  require EXTRA (remove): ' . implode(', ', $issues['extraReq']) . "\n";
        }
        if ($issues['missingDev'] !== []) {
            $output .= '  require-dev MISSING: ' . implode(', ', $issues['missingDev']) . "\n";
        }
        if ($issues['extraDev'] !== []) {
            $output .= '  require-dev EXTRA (remove): ' . implode(', ', $issues['extraDev']) . "\n";
        }
    }

    return $output;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $repoRoot = detectDependencyRepoRoot(getcwd());

    if ($repoRoot === null) {
        fwrite(STDERR, "packages/ not found. Run from repo root.\n");
        exit(1);
    }

    echo renderDependencyReport(buildDependencyReport($repoRoot));
}
