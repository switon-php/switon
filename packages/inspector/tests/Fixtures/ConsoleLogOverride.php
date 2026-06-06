<?php

declare(strict_types=1);

namespace Switon\Inspector;

use Stringable;

use function date;
use function sprintf;

/**
 * Override console_log in the Switon\Inspector namespace for testing.
 *
 * Since DataDump calls console_log() without a leading backslash,
 * PHP resolves the namespaced version first, redirecting STDERR
 * output to stdout where ob_start() can capture it.
 */
function console_log(string $level, string|Stringable $message, array $context = []): void
{
    echo sprintf('[%s][%s]: %s', date('c'), $level, $message) . PHP_EOL;
}
