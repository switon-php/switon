<?php

declare(strict_types=1);

namespace Switon\Console;

use function getenv;
use function is_numeric;
use function is_string;
use function preg_match;
use function shell_exec;
use function trim;

/**
 * Cross-platform terminal width detector with caching.
 *
 * Guidance: Width detection is best-effort; callers should tolerate fallback defaults.
 */
class TerminalWidthDetector implements TerminalWidthDetectorInterface
{
    protected ?int $cachedDetectedWidth = null;
    protected int $minWidth = 20;

    /**
     * Returns the detected terminal width, or <code>max($default, $minWidth)</code> when detection is too small.
     */
    public function getWidth(int $default = 80): int
    {
        if ($this->cachedDetectedWidth !== null) {
            return $this->cachedDetectedWidth < $this->minWidth
                ? max($default, $this->minWidth)
                : $this->cachedDetectedWidth;
        }

        $this->cachedDetectedWidth = $this->detectWidth();
        return $this->cachedDetectedWidth < $this->minWidth
            ? max($default, $this->minWidth)
            : $this->cachedDetectedWidth;
    }

    /**
     * Clears the cached width so the next lookup re-detects the terminal.
     */
    public function clearCache(): void
    {
        $this->cachedDetectedWidth = null;
    }

    protected function detectWidth(): int
    {
        $columns = getenv('COLUMNS');
        if ($columns !== false && is_numeric($columns)) {
            $width = (int)$columns;
            if ($width >= $this->minWidth) {
                return $width;
            }
        }

        if (DIRECTORY_SEPARATOR === '/') {
            return $this->detectUnixWidth();
        }

        return $this->detectWindowsWidth();
    }

    protected function detectUnixWidth(): int
    {
        $stty = $this->runShellCommand('stty size 2>/dev/null');
        if ($stty && preg_match('/\d+\s+(\d+)/', trim($stty), $matches)) {
            $width = (int)$matches[1];
            if ($width >= $this->minWidth) {
                return $width;
            }
        }

        $tput = $this->runShellCommand('tput cols 2>/dev/null');
        if ($tput && is_numeric(trim($tput))) {
            $width = (int)trim($tput);
            if ($width >= $this->minWidth) {
                return $width;
            }
        }

        return 0;
    }

    protected function detectWindowsWidth(): int
    {
        $mode = $this->runShellCommand('mode con 2>nul');
        if ($mode) {
            if (preg_match('/Columns:\s*(\d+)/i', $mode, $matches)) {
                $width = (int)$matches[1];
                if ($width >= $this->minWidth) {
                    return $width;
                }
            }
            if (preg_match('/列:\s*(\d+)/', $mode, $matches)) {
                $width = (int)$matches[1];
                if ($width >= $this->minWidth) {
                    return $width;
                }
            }
        }

        $ps = $this->runShellCommand('powershell -Command "[Console]::WindowWidth" 2>nul');
        if ($ps && is_numeric(trim($ps))) {
            $width = (int)trim($ps);
            if ($width >= $this->minWidth) {
                return $width;
            }
        }

        return 0;
    }

    protected function runShellCommand(string $command): ?string
    {
        $output = shell_exec($command);
        return is_string($output) ? $output : null;
    }
}
