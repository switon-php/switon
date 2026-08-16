<?php

declare(strict_types=1);

namespace Switon\Lint;

use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Lint\Driver\LintDriverInterface;
use Throwable;

/**
 * Routes `lint()` by file extension via `$drivers`
 * (`php` / `js` / `go` / `sh` / `py` / `json` / `rb` / `lua` / `xml` → driver).
 *
 * `attach()` is syntax-only; pass `$deep = true` to `lint()` for analyzers.
 *
 * @see \Switon\Lint\LinterInterface
 */
class Linter implements LinterInterface
{
    /**
     * Extension (no dot) → driver. Apps merge more languages here.
     *
     * @var array<string, LintDriverInterface>
     */
    #[Autowired(instances: true)] protected array $drivers = [
        'php' => Driver\PhpLintDriver::class,
        'js' => Driver\JsLintDriver::class,
        'mjs' => Driver\JsLintDriver::class,
        'cjs' => Driver\JsLintDriver::class,
        'go' => Driver\GoLintDriver::class,
        'sh' => Driver\BashLintDriver::class,
        'bash' => Driver\BashLintDriver::class,
        'py' => Driver\PythonLintDriver::class,
        'json' => Driver\JsonLintDriver::class,
        'rb' => Driver\RubyLintDriver::class,
        'lua' => Driver\LuaLintDriver::class,
        'xml' => Driver\XmlLintDriver::class,
    ];

    /** When false, `attach()` is a no-op (e.g. bulk codegen). */
    #[Autowired] protected bool $attachEnabled = true;

    #[Autowired] protected FilesystemInterface $filesystem;

    public function supports(string $path): bool
    {
        if ($path === '' || !$this->filesystem->isFile($path)) {
            return false;
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' && isset($this->drivers[$extension]);
    }

    public function lint(string $path, callable $shouldAbort, bool $deep = false): array
    {
        if ($path === '' || !$this->filesystem->isFile($path)) {
            Exception::raise('File not found: "{path}".', ['path' => $path]);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '') {
            Exception::raise('No file extension for lint: "{path}".', ['path' => $path]);
        }

        $driver = $this->drivers[$extension] ?? null;
        if ($driver === null) {
            Exception::raise('No lint driver for .{extension} ({path}).', [
                'extension' => $extension,
                'path' => $path,
            ]);
        }

        return $driver->lint($path, $shouldAbort, $deep);
    }

    public function attach(array $payload, string|array $paths, callable $shouldAbort): array
    {
        if (!$this->attachEnabled) {
            return $payload;
        }

        $paths = array_values(array_unique(is_array($paths) ? $paths : [$paths]));
        $reports = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || !$this->supports($path)) {
                continue;
            }
            try {
                $reports[] = $this->lint($path, $shouldAbort, false);
            } catch (Throwable) {
                // Never fail the caller because a linter crashed.
            }
        }

        if ($reports === []) {
            return $payload;
        }

        $payload['lint'] = count($reports) === 1 ? $reports[0] : $reports;

        return $payload;
    }
}
