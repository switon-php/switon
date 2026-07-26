<?php

declare(strict_types=1);

use Switon\Core\Runtime;

// Avoid Xdebug step-debug connection stalls when PHPUnit is invoked without `php -d xdebug.mode=off`.
if (function_exists('xdebug_set_mode')) {
    xdebug_set_mode(XDEBUG_MODE_OFF);
} elseif (extension_loaded('xdebug')) {
    ini_set('xdebug.mode', 'off');
    ini_set('xdebug.start_with_request', '0');
}

Runtime::setCoroutineEnabled(false);

/**
 * Register package test autoloads that the repo-root Composer autoloader does not expose.
 */
function switon_register_psr4_autoload(array $mapping, string $packageRoot): void
{
    foreach ($mapping as $namespace => $path) {
        $fullPath = $packageRoot . '/' . $path;
        if (!is_dir($fullPath)) {
            continue;
        }

        spl_autoload_register(static function (string $class) use ($namespace, $fullPath): void {
            $prefix = rtrim($namespace, '\\');
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relativeClass = substr($class, strlen($prefix));
            $file = $fullPath . str_replace('\\', '/', ltrim($relativeClass, '\\')) . '.php';
            if (file_exists($file)) {
                require $file;
            }
        }, true);
    }
}

/**
 * Register package file autoloads relative to one package root.
 *
 * @param list<string> $files
 */
function switon_register_files_autoload(array $files, string $packageRoot): void
{
    foreach ($files as $file) {
        $fullPath = $packageRoot . '/' . $file;
        if (file_exists($fullPath)) {
            require_once $fullPath;
        }
    }
}

/**
 * Register runtime autoloads for all monorepo packages.
 */
function switon_register_monorepo_package_autoloads(string $repoRoot): void
{
    foreach (glob($repoRoot . '/packages/*/composer.json') ?: [] as $composerJson) {
        $packageRoot = dirname($composerJson);
        $composerConfig = json_decode(file_get_contents($composerJson), true);
        if (!is_array($composerConfig)) {
            continue;
        }

        $autoload = $composerConfig['autoload'] ?? [];
        if (isset($autoload['psr-4']) && is_array($autoload['psr-4'])) {
            switon_register_psr4_autoload($autoload['psr-4'], $packageRoot);
        }

        if (isset($autoload['files']) && is_array($autoload['files'])) {
            switon_register_files_autoload($autoload['files'], $packageRoot);
        }
    }
}

function switon_register_monorepo_test_autoloads(string $packageRoot, string $repoRoot): void
{
    $packageComposerJson = $packageRoot . '/composer.json';
    if (!file_exists($packageComposerJson)) {
        return;
    }

    $composerConfig = json_decode(file_get_contents($packageComposerJson), true);
    if (!is_array($composerConfig)) {
        return;
    }

    if (isset($composerConfig['autoload-dev']['psr-4']) && is_array($composerConfig['autoload-dev']['psr-4'])) {
        switon_register_psr4_autoload($composerConfig['autoload-dev']['psr-4'], $packageRoot);
    }

    if (isset($composerConfig['autoload-dev']['files']) && is_array($composerConfig['autoload-dev']['files'])) {
        switon_register_files_autoload($composerConfig['autoload-dev']['files'], $packageRoot);
    }

    $gmpOverride = $packageRoot . '/tests/gmp-function-override.php';
    if (file_exists($gmpOverride)) {
        require_once $gmpOverride;
    }

    // In the monorepo, the package under test lives under {repoRoot}/packages/*. Its own vendor/ is a stale
    // split-repo snapshot whose Composer autoloader would PREPEND itself and shadow the current package sources
    // (already registered from packages/*/src). Only load a package-local vendor when running as a standalone
    // split repo (package root == repo root), so monorepo runs always resolve against the root vendor + sources.
    if (rtrim($packageRoot, '/') === rtrim($repoRoot, '/')) {
        $packageVendor = $packageRoot . '/vendor/autoload.php';
        if (file_exists($packageVendor)) {
            require_once $packageVendor;
        }
    }
}

$repoRoot = dirname(__DIR__);
$repoAutoload = $repoRoot . '/vendor/autoload.php';
if (!file_exists($repoAutoload)) {
    throw new RuntimeException(
        'Composer autoloader not found in repo-root: ' . $repoAutoload . '. ' .
        'Please run "composer install" in the repository root directory.'
    );
}

require_once $repoAutoload;
switon_register_monorepo_package_autoloads($repoRoot);

if (!isset($_SERVER['argv']) || !is_array($_SERVER['argv'])) {
    return;
}

$argv = $_SERVER['argv'];
$configIndex = array_search('--configuration', $argv, true);
if ($configIndex === false) {
    $configIndex = array_search('-c', $argv, true);
}

if ($configIndex === false || !isset($argv[$configIndex + 1])) {
    return;
}

$configPath = realpath($argv[$configIndex + 1]) ?: realpath(getcwd() . '/' . $argv[$configIndex + 1]);
if ($configPath === false) {
    return;
}

$packageRoot = dirname($configPath, 2);
switon_register_monorepo_test_autoloads($packageRoot, $repoRoot);
