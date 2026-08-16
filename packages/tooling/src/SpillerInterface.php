<?php

declare(strict_types=1);

namespace Switon\Tooling;

use SplFileInfo;

/**
 * Spill oversized bodies under a datadir → inline text or recall preview.
 *
 * Relative `$file` is both write path and {@see \Switon\Ai\ToolResult::$recall} pointer.
 * `$datadir` may be a path alias; I/O goes through {@see \Switon\Core\FilesystemInterface}.
 *
 * @see \Switon\Tooling\Spiller
 */
interface SpillerInterface
{
    /**
     * Put oversized output under `$datadir`; return inline text or a truncated preview.
     *
     * Guidance: callers own thresholds; this interface is mechanism only.
     *
     * @param string $datadir Absolute directory or path alias (alias-aware via Filesystem).
     * @param string $file Relative path inside `$datadir` (e.g. `tool-result/call_x-stdout.txt`).
     * @param string|SplFileInfo $data Inline body, or a file already holding the full body.
     * @param int $budgetKiB Inline / spill-start threshold in KiB.
     * @param int $keep Preview edge length in bytes.
     *
     * @throws \Switon\Tooling\Exception for empty datadir, non-relative `$file`, missing spill file, or unreadable preview target
     */
    public function spill(string $datadir, string $file, string|SplFileInfo $data, int $budgetKiB, int $keep): string;
}
