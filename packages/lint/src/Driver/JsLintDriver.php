<?php

declare(strict_types=1);

namespace Switon\Lint\Driver;

use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Core\Path;
use Switon\Lint\ProcessInterface;

use function is_array;
use function is_int;
use function is_string;
use function json_decode;

/**
 * JS: always `node --check`; with `$deep`, ESLint when `node_modules/.bin/eslint` is found
 * walking up from the file (cached lazily). Wired for `js` / `mjs` / `cjs`.
 *
 * @see \Switon\Lint\Driver\LintDriverInterface
 */
class JsLintDriver implements LintDriverInterface
{
    #[Autowired] protected ProcessInterface $process;
    #[Autowired] protected FilesystemInterface $filesystem;

    #[Autowired] protected string $nodeBinary = 'node';

    /**
     * Lazy-resolved ESLint location (set on first successful discover).
     *
     * @var array{bin: string, cwd: string}|null
     */
    protected ?array $eslint = null;

    public function lint(string $path, callable $shouldAbort, bool $deep = false): array
    {
        $nodeCheck = $this->nodeCheck($path, $shouldAbort);
        $tools = [['name' => 'node --check', 'ok' => $nodeCheck === []]];
        $diagnostics = $nodeCheck;

        if ($deep) {
            $eslint = $this->resolveEslint($path);
            if ($eslint !== null) {
                $fromEslint = $this->runEslint($path, $eslint, $shouldAbort);
                $tools[] = ['name' => 'eslint', 'ok' => $fromEslint === []];
                $diagnostics = [...$diagnostics, ...$fromEslint];
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
    protected function nodeCheck(string $path, callable $shouldAbort): array
    {
        $result = $this->process->run(
            [$this->nodeBinary, '--check', $path],
            $shouldAbort,
            null
        );

        if (($result['exit'] ?? 1) === 0) {
            return [];
        }

        $blob = trim(($result['stdout'] ?? '') . "\n" . ($result['stderr'] ?? ''));
        $line = 1;
        if (preg_match('/:(\d+)(?::\d+)?\b/', $blob, $m) === 1) {
            $line = max(1, (int) $m[1]);
        }

        $message = $blob !== '' ? $blob : 'node --check failed';
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return [[
            'line' => $line,
            'severity' => 'error',
            'message' => $message,
        ]];
    }

    /**
     * Reuse cached locate when the file sits under the discovered project `cwd`;
     * otherwise walk from the file up to filesystem root.
     *
     * @return array{bin: string, cwd: string}|null
     */
    protected function resolveEslint(string $path): ?array
    {
        if ($this->eslint !== null && $this->pathUnder($path, $this->eslint['cwd'])) {
            return $this->eslint;
        }

        $found = $this->discoverEslint($path);
        if ($found !== null) {
            $this->eslint = $found;
        }

        return $found;
    }

    /**
     * Walk `dirname(file)` → … → filesystem root for `node_modules/.bin/eslint`.
     *
     * @return array{bin: string, cwd: string}|null
     */
    protected function discoverEslint(string $path): ?array
    {
        $dir = dirname($path);
        while (true) {
            $bin = $dir . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . '.bin' . DIRECTORY_SEPARATOR . 'eslint';
            if ($this->filesystem->isFile($bin)) {
                return ['bin' => $bin, 'cwd' => $dir];
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
     * @param array{bin: string, cwd: string} $eslint
     *
     * @return list<array{line: int, severity: string, message: string, code?: string}>
     */
    protected function runEslint(string $path, array $eslint, callable $shouldAbort): array
    {
        $result = $this->process->run(
            [$eslint['bin'], '--format', 'json', '--no-error-on-unmatched-pattern', $path],
            $shouldAbort,
            $eslint['cwd']
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
                'message' => 'eslint returned non-JSON output',
            ]];
        }

        $out = [];
        foreach ($decoded as $fileReport) {
            if (!is_array($fileReport)) {
                continue;
            }
            $messages = $fileReport['messages'] ?? [];
            if (!is_array($messages)) {
                continue;
            }
            foreach ($messages as $msg) {
                if (!is_array($msg)) {
                    continue;
                }
                $text = $msg['message'] ?? null;
                if (!is_string($text) || $text === '') {
                    continue;
                }
                $line = $msg['line'] ?? 1;
                $severityNum = $msg['severity'] ?? 2;
                $row = [
                    'line' => is_int($line) && $line > 0 ? $line : 1,
                    'severity' => (is_int($severityNum) && $severityNum <= 1) ? 'warning' : 'error',
                    'message' => $text,
                ];
                $ruleId = $msg['ruleId'] ?? null;
                if (is_string($ruleId) && $ruleId !== '') {
                    $row['code'] = $ruleId;
                }
                $out[] = $row;
            }
        }

        return $out;
    }
}
