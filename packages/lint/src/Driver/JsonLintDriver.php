<?php

declare(strict_types=1);

namespace Switon\Lint\Driver;

use JsonException;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\FilesystemInterface;

/**
 * JSON: syntax-only via `json_decode` (ext-json). Wired for `json`.
 *
 * @see \Switon\Lint\Driver\LintDriverInterface
 */
class JsonLintDriver implements LintDriverInterface
{
    #[Autowired] protected FilesystemInterface $filesystem;

    public function lint(string $path, callable $shouldAbort, bool $deep = false): array
    {
        unset($deep);
        if ($shouldAbort()) {
            return [
                'path' => $path,
                'tools' => [['name' => 'json', 'ok' => false]],
                'diagnostics' => [[
                    'line' => 1,
                    'severity' => 'error',
                    'message' => 'lint aborted',
                ]],
            ];
        }

        $syntax = $this->decodeFile($path);

        return [
            'path' => $path,
            'tools' => [['name' => 'json', 'ok' => $syntax === []]],
            'diagnostics' => $syntax,
        ];
    }

    /**
     * @return list<array{line: int, severity: string, message: string}>
     */
    protected function decodeFile(string $path): array
    {
        try {
            $raw = $this->filesystem->read($path);
        } catch (FileNotFoundException) {
            return [[
                'line' => 1,
                'severity' => 'error',
                'message' => 'failed to read file',
            ]];
        }

        try {
            json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $message = $e->getMessage();
            $line = 1;
            if (preg_match('/\bline\s+(\d+)\b/i', $message, $m) === 1) {
                $line = max(1, (int) $m[1]);
            }

            return [[
                'line' => $line,
                'severity' => 'error',
                'message' => $message,
            ]];
        }

        return [];
    }
}
