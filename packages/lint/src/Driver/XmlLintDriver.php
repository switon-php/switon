<?php

declare(strict_types=1);

namespace Switon\Lint\Driver;

use LibXMLError;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\FilesystemInterface;

/**
 * XML: syntax-only via libxml (`simplexml_load_string`). Wired for `xml`.
 *
 * @see \Switon\Lint\Driver\LintDriverInterface
 */
class XmlLintDriver implements LintDriverInterface
{
    #[Autowired] protected FilesystemInterface $filesystem;

    public function lint(string $path, callable $shouldAbort, bool $deep = false): array
    {
        unset($deep);
        if ($shouldAbort()) {
            return [
                'path' => $path,
                'tools' => [['name' => 'xml', 'ok' => false]],
                'diagnostics' => [[
                    'line' => 1,
                    'severity' => 'error',
                    'message' => 'lint aborted',
                ]],
            ];
        }

        $syntax = $this->parseFile($path);

        return [
            'path' => $path,
            'tools' => [['name' => 'xml', 'ok' => $syntax === []]],
            'diagnostics' => $syntax,
        ];
    }

    /**
     * @return list<array{line: int, severity: string, message: string}>
     */
    protected function parseFile(string $path): array
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

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $parsed = simplexml_load_string($raw);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($parsed !== false && $errors === []) {
            return [];
        }

        if ($errors === []) {
            return [[
                'line' => 1,
                'severity' => 'error',
                'message' => 'invalid XML',
            ]];
        }

        $out = [];
        foreach ($errors as $error) {
            if (!$error instanceof LibXMLError) {
                continue;
            }
            $message = trim($error->message);
            if ($message === '') {
                continue;
            }
            $out[] = [
                'line' => $error->line > 0 ? $error->line : 1,
                'severity' => 'error',
                'message' => preg_replace('/\s+/', ' ', $message) ?? $message,
            ];
        }

        return $out !== [] ? $out : [[
            'line' => 1,
            'severity' => 'error',
            'message' => 'invalid XML',
        ]];
    }
}
