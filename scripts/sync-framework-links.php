<?php

declare(strict_types=1);

/**
 * Build framework/ IDE inspection workspace: Switon symlinks, composer.json, phpstan.neon.
 *
 * Switon: framework/Switon/{Segment}/ → packages/{name}/src (autoload.psr-4 in generated composer.json).
 * FIG PSR + PHPStan: require / require-dev only; run `composer install` in framework/ (no Psr symlinks).
 */
$repoRoot = dirname(__DIR__);
$frameworkRoot = $repoRoot . '/framework';
$switonRoot = $frameworkRoot . '/Switon';
$packagesGlob = $repoRoot . '/packages/*/composer.json';

$dryRun = in_array('--dry-run', $argv, true);
$noInstall = in_array('--no-install', $argv, true);
$quiet = in_array('--quiet', $argv, true) || in_array('-q', $argv, true);

/** @var array<string, string> segment => package */
$plannedSwiton = [];
/** @var list<string> */
$skipped = [];
/** @var list<string> */
$errors = [];

foreach (glob($packagesGlob) ?: [] as $composerFile) {
    $package = basename(dirname($composerFile));
    $result = planSwitonLink($composerFile, $package);

    if ($result['error'] !== null) {
        $skipped[] = sprintf('%s (%s)', $package, $result['error']);
        continue;
    }

    $segment = $result['segment'];
    if (isset($plannedSwiton[$segment]) && $plannedSwiton[$segment] !== $package) {
        $errors[] = sprintf(
            'namespace segment "%s" claimed by both packages/%s and packages/%s',
            $segment,
            $plannedSwiton[$segment],
            $package,
        );
        continue;
    }

    $plannedSwiton[$segment] = $package;
}

if ($errors !== []) {
    fwrite(STDERR, "sync-framework-links: namespace conflicts:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  - $error\n");
    }
    exit(1);
}

if (!$dryRun) {
    if (!is_dir($frameworkRoot)) {
        mkdir($frameworkRoot, 0775, true);
    }
    if (!is_dir($switonRoot)) {
        mkdir($switonRoot, 0775, true);
    }
}

/** @var list<string> linked */
$linked = [];
/** @var list<string> removed */
$removed = [];

/** @var array<string, string> display path => planned entry key for cleanup */
$plannedSwitonPaths = [];

foreach ($plannedSwiton as $segment => $package) {
    $srcDir = $repoRoot . "/packages/$package/src";
    if (!is_dir($srcDir)) {
        $skipped[] = sprintf('%s (missing packages/%s/src)', $package, $package);
        unset($plannedSwiton[$segment]);
        continue;
    }

    $displayPath = "Switon/$segment";
    $plannedSwitonPaths[$displayPath] = $segment;
    $error = applySymlink(
        linkPath: "$switonRoot/$segment",
        relativeTarget: "../../packages/$package/src",
        srcDir: $srcDir,
        displayPath: $displayPath,
        targetLabel: "packages/$package/src",
        dryRun: $dryRun,
        linked: $linked,
        removed: $removed,
    );
    if ($error !== null) {
        $errors[] = $error;
    }
}

if ($errors !== []) {
    fwrite(STDERR, "sync-framework-links: errors:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  - $error\n");
    }
    exit(1);
}

cleanupStaleSymlinks($switonRoot, array_keys($plannedSwitonPaths), 'Switon', $dryRun, $removed);
removeLegacyPsrTree($frameworkRoot, $dryRun, $removed);

/** Remove pre-PSR-layout symlinks directly under framework/ (framework/{Segment}). */
if (is_dir($frameworkRoot)) {
    foreach (scandir($frameworkRoot) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = "$frameworkRoot/$entry";
        if (!is_link($path)) {
            continue;
        }

        if ($dryRun) {
            $removed[] = "$entry (would remove flat legacy link)";
            continue;
        }

        unlink($path);
        $removed[] = "$entry (flat legacy link)";
    }
}

$filesWritten = writeFrameworkWorkspace($frameworkRoot, $plannedSwiton, $repoRoot, $dryRun);
if ($filesWritten && !$quiet) {
    printf(
        "  write framework/composer.json + phpstan.neon (%d Switon PSR-4 roots)%s\n",
        count($plannedSwiton),
        $dryRun ? ' [dry-run]' : '',
    );
}

if (!$dryRun && !$noInstall && ($filesWritten || !is_file("$frameworkRoot/vendor/autoload.php"))) {
    runFrameworkComposerInstall($frameworkRoot, $quiet);
}

if (!$quiet) {
    printf(
        "sync-framework-links: %d linked, %d skipped, %d removed%s\n",
        count($linked),
        count($skipped),
        count($removed),
        $dryRun ? ' [dry-run]' : '',
    );

    foreach ($linked as $line) {
        printf("  link  %s\n", $line);
    }
    foreach ($removed as $line) {
        printf("  drop  %s\n", $line);
    }
    foreach ($skipped as $line) {
        printf("  skip  %s\n", $line);
    }

    if (!$dryRun) {
        fwrite(STDOUT, "\nPhpStorm: PHPStan executable = framework/vendor/bin/phpstan; config = framework/phpstan.neon\n");
        fwrite(STDOUT, "Note: vendor/phpstan/phpstan ships as phpstan.phar only (official distribution, not missing sources).\n");
        fwrite(STDOUT, "CLI: cd framework && composer phpstan\n");
        fwrite(STDOUT, "Inspect scope: file[framework/Switon/**]:* ; exclude packages/, repo vendor/, app-admin/\n");
    }
}

/**
 * @param list<string> $linked
 * @param list<string> $removed
 */
function applySymlink(
    string $linkPath,
    string $relativeTarget,
    string $srcDir,
    string $displayPath,
    string $targetLabel,
    bool $dryRun,
    array &$linked,
    array &$removed,
): ?string {
    if (is_link($linkPath)) {
        $current = readlink($linkPath);
        if ($current === $relativeTarget || realpath($linkPath) === realpath($srcDir)) {
            $linked[] = sprintf('%s -> %s (unchanged)', $displayPath, $targetLabel);
            return null;
        }

        if (!$dryRun) {
            unlink($linkPath);
        }
        $removed[] = "$displayPath (stale target)";
    } elseif (file_exists($linkPath)) {
        return "framework/$displayPath exists and is not a symlink";
    }

    if ($dryRun) {
        $linked[] = sprintf('%s -> %s (would link)', $displayPath, $targetLabel);
        return null;
    }

    if (!symlink($relativeTarget, $linkPath)) {
        return "failed to link framework/$displayPath";
    }

    $linked[] = sprintf('%s -> %s', $displayPath, $targetLabel);

    return null;
}

/**
 * @param list<string> $plannedEntries display paths or segment names
 * @param list<string> $removed
 */
function cleanupStaleSymlinks(
    string $scanRoot,
    array $plannedEntries,
    string $label,
    bool $dryRun,
    array &$removed,
): void {
    $planned = array_fill_keys($plannedEntries, true);

    foreach (scandir($scanRoot) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $displayPath = "$label/$entry";
        if (isset($planned[$displayPath]) || isset($planned[$entry])) {
            continue;
        }

        $path = "$scanRoot/$entry";
        if (!is_link($path)) {
            continue;
        }

        if ($dryRun) {
            $removed[] = "$displayPath (would remove stale link)";
            continue;
        }

        unlink($path);
        $removed[] = "$displayPath (stale link)";
    }
}

/**
 * @param list<string> $removed
 */
function removeLegacyPsrTree(string $frameworkRoot, bool $dryRun, array &$removed): void
{
    $psrRoot = "$frameworkRoot/Psr";
    if (!is_dir($psrRoot)) {
        return;
    }

    foreach (scandir($psrRoot) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = "$psrRoot/$entry";
        if (!is_link($path)) {
            continue;
        }

        if ($dryRun) {
            $removed[] = "Psr/$entry (would remove legacy Psr symlink)";
            continue;
        }

        unlink($path);
        $removed[] = "Psr/$entry (legacy Psr symlink)";
    }

    if ($dryRun || !is_dir($psrRoot) || scandir($psrRoot) !== ['.', '..']) {
        return;
    }

    rmdir($psrRoot);
}

/**
 * @param array<string, string> $plannedSwiton segment => package
 */
function writeFrameworkWorkspace(string $frameworkRoot, array $plannedSwiton, string $repoRoot, bool $dryRun): bool
{
    if ($plannedSwiton === []) {
        return false;
    }

    $root = readComposerConfig($repoRoot . '/composer.json') ?? [];

    $require = ['php' => '>=8.3'];
    foreach ($root['require'] ?? [] as $name => $constraint) {
        if (is_string($name) && str_starts_with($name, 'psr/')) {
            $require[$name] = $constraint;
        }
    }

    $requireDev = [
        'phpstan/phpstan' => '^2.0',
        'phpstan/extension-installer' => '^1.4',
    ];
    foreach ($requireDev as $package => $default) {
        if (isset($root['require-dev'][$package]) && is_string($root['require-dev'][$package])) {
            $requireDev[$package] = $root['require-dev'][$package];
        }
    }

    $psr4 = [];
    ksort($plannedSwiton);
    foreach ($plannedSwiton as $segment => $_package) {
        $psr4['Switon\\' . $segment . '\\'] = "Switon/$segment/";
    }

    $composer = [
        'name' => 'switon/framework-inspection',
        'description' => 'Generated IDE inspection workspace (not published). Run: composer framework:sync',
        'type' => 'library',
        'license' => 'MIT',
        'require' => $require,
        'require-dev' => $requireDev,
        'autoload' => [
            'psr-4' => $psr4,
        ],
        'config' => [
            'allow-plugins' => [
                'phpstan/extension-installer' => true,
            ],
        ],
        'scripts' => [
            'phpstan' => '@php vendor/bin/phpstan analyse --configuration=phpstan.neon --memory-limit=512M',
        ],
    ];

    $composerJson = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $phpstanNeon = <<<'NEON'
parameters:
    level: 7
    bootstrapFiles:
        - vendor/autoload.php
    paths:
        - Switon
    tmpDir: .phpstan.cache

NEON;

    $composerChanged = writeGeneratedFile("$frameworkRoot/composer.json", $composerJson, $dryRun);
    $changed = $composerChanged || writeGeneratedFile("$frameworkRoot/phpstan.neon", $phpstanNeon, $dryRun);

    if (!$dryRun && $composerChanged && is_file("$frameworkRoot/composer.lock")) {
        unlink("$frameworkRoot/composer.lock");
    }

    return $changed || $dryRun;
}

/** Returns true when the file was created or updated. */
function writeGeneratedFile(string $path, string $contents, bool $dryRun): bool
{
    if ($dryRun) {
        return false;
    }

    if (is_file($path) && file_get_contents($path) === $contents) {
        return false;
    }

    file_put_contents($path, $contents);

    return true;
}

function runFrameworkComposerInstall(string $frameworkRoot, bool $quiet): void
{
    $composer = getenv('COMPOSER_BINARY') ?: 'composer';
    $command = sprintf(
        '%s update -d %s --no-interaction 2>&1',
        escapeshellarg($composer),
        escapeshellarg($frameworkRoot),
    );

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    if ($exitCode !== 0) {
        fwrite(STDERR, "sync-framework-links: composer install failed in framework/\n");
        foreach ($output as $line) {
            fwrite(STDERR, $line . "\n");
        }
        exit(1);
    }

    if (!$quiet) {
        fwrite(STDOUT, "  run   composer update -d framework/\n");
    }
}

/**
 * @return array{segment: ?string, error: ?string}
 */
function planSwitonLink(string $composerFile, string $package): array
{
    $config = readComposerConfig($composerFile);
    if ($config === null) {
        return ['segment' => null, 'error' => 'invalid composer.json'];
    }

    $psr4 = $config['autoload']['psr-4'] ?? [];
    if ($psr4 === []) {
        return ['segment' => null, 'error' => 'no autoload.psr-4'];
    }

    if (count($psr4) !== 1) {
        return ['segment' => null, 'error' => 'multiple autoload.psr-4 roots'];
    }

    $namespace = rtrim((string) array_key_first($psr4), '\\');
    $path = (string) reset($psr4);

    if ($path !== 'src/') {
        return ['segment' => null, 'error' => "autoload path is \"$path\", expected src/"];
    }

    if (!str_starts_with($namespace, 'Switon\\')) {
        return ['segment' => null, 'error' => "namespace is \"$namespace\", expected Switon\\{{Segment}}"];
    }

    $segment = substr($namespace, strlen('Switon\\'));
    if ($segment === '' || str_contains($segment, '\\')) {
        return ['segment' => null, 'error' => "namespace is \"$namespace\", expected single segment under Switon\\"];
    }

    if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $segment)) {
        return ['segment' => null, 'error' => "invalid namespace segment \"$segment\""];
    }

    return ['segment' => $segment, 'error' => null];
}

/**
 * @return ?array<string, mixed>
 */
function readComposerConfig(string $composerFile): ?array
{
    if (!is_file($composerFile)) {
        return null;
    }

    $raw = file_get_contents($composerFile);
    if ($raw === false) {
        return null;
    }

    $config = json_decode($raw, true);

    return is_array($config) ? $config : null;
}
