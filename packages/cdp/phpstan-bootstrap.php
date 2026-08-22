<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);

foreach ([__DIR__ . '/vendor/autoload.php', $repoRoot . '/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

foreach (glob($repoRoot . '/packages/*/composer.json') ?: [] as $composerJson) {
    $packageRoot = dirname($composerJson);
    $composerConfig = json_decode((string) file_get_contents($composerJson), true);
    if (!is_array($composerConfig)) {
        continue;
    }

    $autoloadConfig = $composerConfig['autoload']['psr-4'] ?? null;
    if (!is_array($autoloadConfig)) {
        continue;
    }

    foreach ($autoloadConfig as $namespace => $path) {
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
            if (is_file($file)) {
                require $file;
            }
        }, true);
    }
}
