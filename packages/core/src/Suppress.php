<?php

declare(strict_types=1);

namespace Switon\Core;

use Throwable;

/**
 * Run side effects without propagating throwables.
 *
 * Use for cleanup tails and listener dispatch where failure must not replace the primary error.
 */
class Suppress
{
    /** @param callable(): mixed $action */
    public static function throwable(callable $action): void
    {
        try {
            $action();
        } catch (Throwable) {
        }
    }
}
