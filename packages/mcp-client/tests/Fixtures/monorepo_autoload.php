<?php

declare(strict_types=1);

/**
 * Minimal autoload for MCP stdio subprocess fixtures (monorepo or split package).
 */
function mcp_client_register_psr4_autoload(array $mapping, string $packageRoot): void
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

function mcp_client_register_monorepo_package_autoloads(string $repoRoot): void
{
    foreach (glob($repoRoot . '/packages/*/composer.json') ?: [] as $composerJson) {
        $packageRoot = dirname($composerJson);
        $composerConfig = json_decode((string) file_get_contents($composerJson), true);
        if (!is_array($composerConfig)) {
            continue;
        }

        $autoload = $composerConfig['autoload'] ?? [];
        if (isset($autoload['psr-4']) && is_array($autoload['psr-4'])) {
            mcp_client_register_psr4_autoload($autoload['psr-4'], $packageRoot);
        }
    }
}

$packageRoot = dirname(__DIR__, 2);
$repoRoot = dirname(__DIR__, 4);
$autoload = null;
$runtimeRoot = null;

if (is_file($packageRoot . '/vendor/autoload.php')) {
    // Split package checkout: vendor lives beside the package root.
    $autoload = $packageRoot . '/vendor/autoload.php';
    $runtimeRoot = $packageRoot;
} elseif (is_file($repoRoot . '/vendor/autoload.php')) {
    // Monorepo checkout: shared vendor at the repository root.
    $autoload = $repoRoot . '/vendor/autoload.php';
    $runtimeRoot = $repoRoot;
}

if ($autoload === null || $runtimeRoot === null) {
    throw new RuntimeException('Unable to locate vendor/autoload.php for MCP stdio fixtures.');
}

require $autoload;

// This bootstrap file name ends in "autoload.php", which Runtime::getRoot() would otherwise
// mistake for the Composer autoloader and derive a wrong project root from. Pin it explicitly.
\Switon\Core\Runtime::setRoot($runtimeRoot);

if ($runtimeRoot === $repoRoot && is_dir($repoRoot . '/packages')) {
    mcp_client_register_monorepo_package_autoloads($repoRoot);
}
