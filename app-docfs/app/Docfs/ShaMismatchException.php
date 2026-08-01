<?php

declare(strict_types=1);

namespace App\Docfs;

use Switon\Core\Exception\RuntimeException;

/**
 * File content sha mismatch (optimistic lock) — for non-MCP paths (e.g. asset HTTP).
 */
final class ShaMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $docFile,
        public readonly string $sha,
    ) {
        parent::__construct('sha mismatch: {file}', ['file' => $docFile]);
    }

    /** @return never */
    public static function mismatch(string $file, string $sha): never
    {
        throw new self($file, $sha);
    }
}
