<?php

declare(strict_types=1);

/**
 * Minimal monorepo autoload for MCP stdio subprocess fixtures.
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

$repoRoot = dirname(__DIR__, 4);
require $repoRoot . '/vendor/autoload.php';

// This bootstrap file name ends in "autoload.php", which Runtime::getRoot() would otherwise
// mistake for the Composer autoloader and derive a wrong project root from. Pin it explicitly.
\Switon\Core\Runtime::setRoot($repoRoot);

mcp_client_register_monorepo_package_autoloads($repoRoot);
