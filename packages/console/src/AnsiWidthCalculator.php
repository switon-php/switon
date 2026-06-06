<?php

declare(strict_types=1);

namespace Switon\Console;

use function function_exists;
use function mb_strwidth;
use function preg_replace;
use function strlen;

/**
 * Helper for ANSI-aware display width calculation.
 *
 * Guidance: Use for console layout only; strip ANSI escapes before measuring visible width.
 */
trait AnsiWidthCalculator
{
    protected function getDisplayWidth(string $text): int
    {
        $cleanText = preg_replace('/\033\[[0-9;]*m/', '', $text);
        if ($cleanText === null) {
            return strlen($text);
        }

        if (function_exists('mb_strwidth')) {
            return mb_strwidth($cleanText, 'UTF-8');
        }

        return strlen($cleanText);
    }
}
