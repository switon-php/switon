<?php

declare(strict_types=1);

namespace Switon\Lint\Driver;

use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Lint\ProcessInterface;

use function is_array;
use function is_int;
use function is_string;
use function json_decode;

/**
 * Shell: always `bash -n` (syntax); with `$deep`, `shellcheck` when on PATH (cached).
 * Wired for `sh` / `bash`.
 *
 * @see \Switon\Lint\Driver\LintDriverInterface
 */
class BashLintDriver implements LintDriverInterface
{
    #[Autowired] protected ProcessInterface $process;
    #[Autowired] protected FilesystemInterface $filesystem;

    #[Autowired] protected string $bashBinary = 'bash';
    #[Autowired] protected string $shellcheckBinary = 'shellcheck';

    /** Lazy PATH probe: null = not yet; false = missing; string = resolved binary. */
    protected string|false|null $shellcheck = null;

    public function lint(string $path, callable $shouldAbort, bool $deep = false): array
    {
        $syntax = $this->bashSyntax($path, $shouldAbort);
        $tools = [['name' => 'bash -n', 'ok' => $syntax === []]];
        $diagnostics = $syntax;

        if ($deep) {
            $bin = $this->resolveShellcheck();
            if ($bin !== null) {
                $fromCheck = $this->runShellcheck($path, $bin, $shouldAbort);
                $tools[] = ['name' => 'shellcheck', 'ok' => $fromCheck === []];
                $diagnostics = [...$diagnostics, ...$fromCheck];
            }
        }

        return [
            'path' => $path,
            'tools' => $tools,
            'diagnostics' => $diagnostics,
        ];
    }

    /** Git bash does not open `C:/...` reliably; `/c/...` is the path it can read. */
    protected function bashPath(string $path): string
    {
        $path = strtr($path, '\\', '/');
        if (preg_match('#^([A-Za-z]):/(.*)$#', $path, $match) !== 1) {
            return $path;
        }

        return '/' . strtolower($match[1]) . '/' . $match[2];
    }

    protected function bashProgram(): string
    {
        if (PHP_OS_FAMILY !== 'Windows' || str_contains($this->bashBinary, '/') || str_contains($this->bashBinary, '\\')) {
            return $this->bashBinary;
        }

        $root = getenv('ProgramFiles');
        $candidate = (is_string($root) && $root !== '' ? $root : 'C:\\Program Files') . '\\Git\\bin\\bash.exe';

        return is_file($candidate) ? $candidate : $this->bashBinary;
    }

    /**
     * @return list<array{line: int, severity: string, message: string}>
     */
    protected function bashSyntax(string $path, callable $shouldAbort): array
    {
        $result = $this->process->run(
            [$this->bashProgram(), '-n', $this->bashPath($path)],
            $shouldAbort,
            null
        );

        if (($result['exit'] ?? 1) === 0) {
            return [];
        }

        $blob = trim(($result['stderr'] ?? '') . "\n" . ($result['stdout'] ?? ''));
        $line = 1;
        if (preg_match('/\bline (\d+)\b/i', $blob, $m) === 1) {
            $line = max(1, (int) $m[1]);
        }

        $message = $blob !== ''
            ? $blob
            : 'bash -n failed (exit ' . (string) ($result['exit'] ?? '') . ' ' . $this->bashProgram() . ')';
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return [[
            'line' => $line,
            'severity' => 'error',
            'message' => $message,
        ]];
    }

    protected function resolveShellcheck(): ?string
    {
        if ($this->shellcheck === false) {
            return null;
        }
        if (is_string($this->shellcheck)) {
            return $this->shellcheck;
        }

        $candidate = $this->shellcheckBinary;
        if ($candidate !== '' && ($this->filesystem->isFile($candidate) || $this->commandOnPath($candidate))) {
            $this->shellcheck = $candidate;

            return $candidate;
        }

        $this->shellcheck = false;

        return null;
    }

    protected function commandOnPath(string $command): bool
    {
        if ($command === '' || str_contains($command, '/') || str_contains($command, '\\')) {
            return $this->filesystem->isFile($command);
        }

        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
        foreach ($paths as $dir) {
            if ($dir === '') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $command;
            if ($this->filesystem->isFile($full) && is_executable($full)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{line: int, severity: string, message: string, code?: string}>
     */
    protected function runShellcheck(string $path, string $bin, callable $shouldAbort): array
    {
        $result = $this->process->run(
            [$bin, '-f', 'json', $path],
            $shouldAbort,
            null
        );

        $stdout = trim($result['stdout'] ?? '');
        if ($stdout === '') {
            if (($result['exit'] ?? 1) === 0) {
                return [];
            }

            $stderr = trim($result['stderr'] ?? '');
            if ($stderr === '') {
                return [];
            }

            return [[
                'line' => 1,
                'severity' => 'error',
                'message' => preg_replace('/\s+/', ' ', $stderr) ?? $stderr,
            ]];
        }

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            return [[
                'line' => 1,
                'severity' => 'error',
                'message' => 'shellcheck returned non-JSON output',
            ]];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = $item['message'] ?? null;
            if (!is_string($text) || $text === '') {
                continue;
            }
            $line = $item['line'] ?? 1;
            $level = $item['level'] ?? 'error';
            $severity = match (is_string($level) ? $level : 'error') {
                'warning', 'info', 'style' => 'warning',
                default => 'error',
            };
            $row = [
                'line' => is_int($line) && $line > 0 ? $line : 1,
                'severity' => $severity,
                'message' => $text,
            ];
            $code = $item['code'] ?? null;
            if (is_int($code)) {
                $row['code'] = 'SC' . $code;
            } elseif (is_string($code) && $code !== '') {
                $row['code'] = $code;
            }
            $out[] = $row;
        }

        return $out;
    }
}
