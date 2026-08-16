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
 * PHP: always `php -l`; with `$deep`, PHPStan when `vendor/bin/phpstan` is found
 * walking up from the file (cached lazily).
 *
 * @see \Switon\Lint\Driver\LintDriverInterface
 */
class PhpLintDriver implements LintDriverInterface
{
    #[Autowired] protected ProcessInterface $process;
    #[Autowired] protected FilesystemInterface $filesystem;

    /**
     * Lazy-resolved PHPStan location (set on first successful discover).
     *
     * @var array{bin: string, config: ?string, cwd: string}|null
     */
    protected ?array $phpstan = null;

    public function lint(string $path, callable $shouldAbort, bool $deep = false): array
    {
        $phpLint = $this->phpLint($path, $shouldAbort);
        $tools = [['name' => 'php -l', 'ok' => $phpLint === []]];
        $diagnostics = $phpLint;

        if ($deep) {
            $phpstan = $this->resolvePhpstan($path);
            if ($phpstan !== null) {
                $stan = $this->runPhpstan($path, $phpstan, $shouldAbort);
                $tools[] = ['name' => 'phpstan', 'ok' => $stan === []];
                $diagnostics = [...$diagnostics, ...$stan];
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
    protected function phpLint(string $path, callable $shouldAbort): array
    {
        $result = $this->process->run(
            [PHP_BINARY, '-l', $path],
            $shouldAbort,
            null
        );

        if (($result['exit'] ?? 1) === 0) {
            return [];
        }

        $blob = trim(($result['stdout'] ?? '') . "\n" . ($result['stderr'] ?? ''));
        $line = 1;
        if (preg_match('/on line (\d+)/i', $blob, $m) === 1) {
            $line = max(1, (int) $m[1]);
        }

        $message = $blob !== '' ? $blob : 'php -l failed';
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
     * @return array{bin: string, config: ?string, cwd: string}|null
     */
    protected function resolvePhpstan(string $path): ?array
    {
        if ($this->phpstan !== null && $this->pathUnder($path, $this->phpstan['cwd'])) {
            return $this->phpstan;
        }

        $found = $this->discoverPhpstan($path);
        if ($found !== null) {
            $this->phpstan = $found;
        }

        return $found;
    }

    /**
     * Walk `dirname(file)` → … → filesystem root for `vendor/bin/phpstan`.
     *
     * @return array{bin: string, config: ?string, cwd: string}|null
     */
    protected function discoverPhpstan(string $path): ?array
    {
        $dir = dirname($path);
        while (true) {
            $bin = $dir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpstan';
            if ($this->filesystem->isFile($bin)) {
                $config = null;
                foreach (['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'] as $name) {
                    $candidate = $dir . DIRECTORY_SEPARATOR . $name;
                    if ($this->filesystem->isFile($candidate)) {
                        $config = $candidate;
                        break;
                    }
                }

                return ['bin' => $bin, 'config' => $config, 'cwd' => $dir];
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
     * @param array{bin: string, config: ?string, cwd: string} $phpstan
     *
     * @return list<array{line: int, severity: string, message: string, code?: string}>
     */
    protected function runPhpstan(string $path, array $phpstan, callable $shouldAbort): array
    {
        $command = [PHP_BINARY, $phpstan['bin'], 'analyse', '--error-format=json', '--no-progress', '--memory-limit=512M'];
        if ($phpstan['config'] !== null) {
            $command[] = '--configuration=' . $phpstan['config'];
        }
        $command[] = $path;

        $result = $this->process->run(
            $command,
            $shouldAbort,
            $phpstan['cwd']
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
                'message' => 'phpstan returned non-JSON output',
            ]];
        }

        $out = [];
        $files = $decoded['files'] ?? [];
        if (!is_array($files)) {
            return $out;
        }

        foreach ($files as $info) {
            if (!is_array($info)) {
                continue;
            }
            $messages = $info['messages'] ?? [];
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
                $row = [
                    'line' => is_int($line) && $line > 0 ? $line : 1,
                    'severity' => 'error',
                    'message' => $text,
                ];
                $identifier = $msg['identifier'] ?? null;
                if (is_string($identifier) && $identifier !== '') {
                    $row['code'] = $identifier;
                }
                $out[] = $row;
            }
        }

        return $out;
    }
}
