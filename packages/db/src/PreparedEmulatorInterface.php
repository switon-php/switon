<?php

declare(strict_types=1);

namespace Switon\Db;

/**
 * Contract for rendering named-parameter SQL with bind values for diagnostics.
 *
 * Use for logging/debug display only, not for executing user input in production.
 *
 * @see \Switon\Db\PreparedEmulator
 * @see \Switon\Db\Inspector\SqlCollector
 */
interface PreparedEmulatorInterface
{
    /**
     * Renders SQL by replacing named placeholders with escaped values.
     *
     * Named placeholders only (<code>:name</code>). Positional binds are not emulated.
     * If bind keys are numeric, implementations may return the original SQL unchanged.
     *
     * @param array<string, mixed> $bind Bind values keyed without the ':' prefix
     * @param int $preservedStrLength Max rendered string length for string binds (-1 means no limit)
     */
    public function emulate(string $sql, array $bind, int $preservedStrLength = -1): string;
}
