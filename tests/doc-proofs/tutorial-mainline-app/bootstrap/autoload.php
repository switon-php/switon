<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$repoRoot = dirname(__DIR__, 4);
$packageComposerFiles = glob($repoRoot . '/packages/*/composer.json') ?: [];

$psr4 = [];
$autoloadFiles = [
    $repoRoot . '/packages/core/helpers.php',
];
$composerExtra = [];

foreach ($packageComposerFiles as $composerFile) {
    $packageDir = dirname($composerFile);
    $packageConfig = json_decode((string) file_get_contents($composerFile), true);
    if (!is_array($packageConfig)) {
        continue;
    }

    foreach (($packageConfig['autoload']['psr-4'] ?? []) as $prefix => $path) {
        $psr4[$prefix][] = $packageDir . '/' . trim((string) $path, '/');
    }

    foreach (($packageConfig['autoload']['files'] ?? []) as $path) {
        $autoloadFiles[] = $packageDir . '/' . ltrim((string) $path, '/');
    }

    if (isset($packageConfig['name'], $packageConfig['extra']) && is_array($packageConfig['extra'])) {
        $composerExtra[$packageConfig['name']] = $packageConfig['extra'];
    }
}

spl_autoload_register(static function (string $class) use ($projectRoot, $psr4): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $projectRoot . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }

        return;
    }

    foreach ($psr4 as $prefix => $directories) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relativePath = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        foreach ($directories as $directory) {
            $file = rtrim($directory, '/') . '/' . $relativePath;
            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }
}, true, true);

$loader = require $repoRoot . '/vendor/autoload.php';

foreach (array_unique($autoloadFiles) as $autoloadFile) {
    if (is_file($autoloadFile)) {
        require_once $autoloadFile;
    }
}

$composerExtraCacheFile = $projectRoot . '/vendor/switon/composer-extra.json';
if (!is_file($composerExtraCacheFile)) {
    $composerExtraCacheDir = dirname($composerExtraCacheFile);
    if (!is_dir($composerExtraCacheDir) && !mkdir($composerExtraCacheDir, 0777, true) && !is_dir($composerExtraCacheDir)) {
        throw new RuntimeException('Failed to create composer extra cache directory: ' . $composerExtraCacheDir);
    }

    file_put_contents(
        $composerExtraCacheFile,
        json_encode($composerExtra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    );
}

return $loader;
