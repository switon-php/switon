<?php

declare(strict_types=1);

namespace Switon\Console;

/**
 * Contract for terminal width detection with fallback behavior.
 *
 * Guidance: Use this when output layout needs a best-effort terminal width without depending on a specific shell.
 */
interface TerminalWidthDetectorInterface
{
    public function getWidth(int $default = 80): int;

    public function clearCache(): void;
}
