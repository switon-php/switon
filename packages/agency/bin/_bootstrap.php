<?php

declare(strict_types=1);

/**
 * Bootstrap helper for this package's `bin/*.php` scripts: load Composer's autoloader, then return the project root.
 *
 * Guidance: these scripts run inside the host app, so the app root comes from the process working directory
 * (or from Composer's `vendor/bin/*` proxy, which hands over the installed autoloader). A monorepo checkout
 * resolves {@see \Switon\Agency} from this package's own `src/` because the repo root does not require it.
 * Internal helper — not a composer `bin` entry.
 *
 * @return string Project root, the directory holding `vendor/` (what `Switon\Cli\Runner` boots).
 */

use Switon\Agency\Command\AgentCommand;

$candidates = [];
$proxyAutoload = $GLOBALS['_composer_autoload_path'] ?? null;
if (is_string($proxyAutoload) && $proxyAutoload !== '') {
    $candidates[] = $proxyAutoload;
}

$cwd = getcwd();
if (is_string($cwd) && $cwd !== '') {
    $candidates[] = rtrim($cwd, '/') . '/vendor/autoload.php';
}

for ($dir = __DIR__; ; $dir = dirname($dir)) {
    $candidates[] = $dir . '/vendor/autoload.php';
    if (dirname($dir) === $dir) {
        break;
    }
}

foreach (array_unique($candidates) as $autoload) {
    if (!is_file($autoload)) {
        continue;
    }

    require_once $autoload;
    if (class_exists(AgentCommand::class)) {
        return dirname((string) realpath($autoload), 2);
    }
}

fwrite(STDERR, <<<'TXT'
Cannot find an app autoloader for this package's bin scripts.
Run them from the app root (the directory holding `vendor/`), for example:
  cd app-coding && php vendor/switon/agency/bin/agent-prompt.php --profile=assist
TXT . "\n");
exit(1);
