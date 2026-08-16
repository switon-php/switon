<?php

declare(strict_types=1);

namespace Switon\Lint\Driver;

use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Core\Path;
use Switon\Lint\ProcessInterface;

/**
 * Go: always `gofmt -e` (syntax); with `$deep`, `go vet` when `go.mod` is found walking up (cached lazily).
 *
 * @see \Switon\Lint\Driver\LintDriverInterface
 */
class GoLintDriver implements LintDriverInterface
{
    #[Autowired] protected ProcessInterface $process;
    #[Autowired] protected FilesystemInterface $filesystem;

    #[Autowired] protected string $gofmtBinary = 'gofmt';
    #[Autowired] protected string $goBinary = 'go';

    /**
     * Lazy-resolved module root (`go.mod` directory).
     *
     * @var array{cwd: string}|null
     */
    protected ?array $module = null;

    public function lint(string $path, callable $shouldAbort, bool $deep = false): array
    {
        $gofmt = $this->gofmtCheck($path, $shouldAbort);
        $tools = [['name' => 'gofmt', 'ok' => $gofmt === []]];
        $diagnostics = $gofmt;

        if ($deep) {
            $module = $this->resolveModule($path);
            if ($module !== null) {
                $vet = $this->runGoVet($path, $module, $shouldAbort);
                $tools[] = ['name' => 'go vet', 'ok' => $vet === []];
                $diagnostics = [...$diagnostics, ...$vet];
            }
        }

        return [
            'path' => $path,
            'tools' => $tools,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return list<array{line: int, severity: string, message: string}>
     */
    protected function gofmtCheck(string $path, callable $shouldAbort): array
    {
        $result = $this->process->run(
            [$this->gofmtBinary, '-e', $path],
            $shouldAbort,
            null
        );

        if (($result['exit'] ?? 1) === 0) {
            return [];
        }

        $blob = trim(($result['stderr'] ?? '') . "\n" . ($result['stdout'] ?? ''));
        $parsed = $this->parseGoDiagnosticLines($blob);
        if ($parsed !== []) {
            return $parsed;
        }

        $message = $blob !== '' ? $blob : 'gofmt failed';
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return [[
            'line' => 1,
            'severity' => 'error',
            'message' => $message,
        ]];
    }

    /**
     * @return array{cwd: string}|null
     */
    protected function resolveModule(string $path): ?array
    {
        if ($this->module !== null && $this->pathUnder($path, $this->module['cwd'])) {
            return $this->module;
        }

        $found = $this->discoverModule($path);
        if ($found !== null) {
            $this->module = $found;
        }

        return $found;
    }

    /**
     * Walk `dirname(file)` → … → filesystem root for `go.mod`.
     *
     * @return array{cwd: string}|null
     */
    protected function discoverModule(string $path): ?array
    {
        $dir = dirname($path);
        while (true) {
            if ($this->filesystem->isFile($dir . DIRECTORY_SEPARATOR . 'go.mod')) {
                return ['cwd' => $dir];
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $dir = $parent;
        }
    }

    protected function pathUnder(string $path, string $root): bool
    {
        $realPath = $this->filesystem->realpath($path);
        $realRoot = $this->filesystem->realpath($root);
        if ($realPath === null || $realRoot === null) {
            return Path::within($root, $path, true);
        }

        return Path::within($realRoot, $realPath, true);
    }

    /**
     * @param array{cwd: string} $module
     *
     * @return list<array{line: int, severity: string, message: string}>
     */
    protected function runGoVet(string $path, array $module, callable $shouldAbort): array
    {
        $packageDir = dirname($path);
        $rel = $this->relativePath($module['cwd'], $packageDir);
        $target = $rel === '' ? '.' : './' . str_replace('\\', '/', $rel);

        $result = $this->process->run(
            [$this->goBinary, 'vet', $target],
            $shouldAbort,
            $module['cwd']
        );

        if (($result['exit'] ?? 1) === 0) {
            return [];
        }

        $blob = trim(($result['stderr'] ?? '') . "\n" . ($result['stdout'] ?? ''));
        $parsed = $this->parseGoDiagnosticLines($blob);
        if ($parsed !== []) {
            return $parsed;
        }

        if ($blob === '') {
            return [];
        }

        $message = preg_replace('/\s+/', ' ', $blob) ?? $blob;

        return [[
            'line' => 1,
            'severity' => 'error',
            'message' => $message,
        ]];
    }

    /**
     * Parse `file:line:col: message` / `vet: file:line:col: message` lines.
     *
     * @return list<array{line: int, severity: string, message: string}>
     */
    protected function parseGoDiagnosticLines(string $blob): array
    {
        if ($blob === '') {
            return [];
        }

        $out = [];
        foreach (preg_split('/\R/', $blob) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/:(\d+)(?::\d+)?:\s*(.+)$/', $line, $m) !== 1) {
                continue;
            }
            $text = trim($m[2]);
            if ($text === '') {
                continue;
            }
            $out[] = [
                'line' => max(1, (int) $m[1]),
                'severity' => 'error',
                'message' => $text,
            ];
        }

        return $out;
    }

    protected function relativePath(string $from, string $to): string
    {
        $fromReal = $this->filesystem->realpath($from) ?? $from;
        $toReal = $this->filesystem->realpath($to) ?? $to;
        $relative = Path::toRelative($fromReal, $toReal);
        if ($relative !== null) {
            return $relative;
        }

        return basename($to);
    }
}
