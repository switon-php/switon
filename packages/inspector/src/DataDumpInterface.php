<?php

declare(strict_types=1);

namespace Switon\Inspector;

/**
 * Defines debug output formatting contract for inspector dumps.
 *
 * Use when replacing the default dump output implementation.
 *
 * @see \Switon\Inspector\DataDump
 */
interface DataDumpInterface
{
    /**
     * Writes one debug message.
     *
     * @param mixed $message Value to render and output
     */
    public function output(mixed $message): void;
}
