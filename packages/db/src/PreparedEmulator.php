<?php

declare(strict_types=1);

namespace Switon\Db;

use function is_bool;
use function is_int;
use function is_string;
use function str_replace;
use function strlen;
use function strtr;
use function substr;

/**
 * Renders named-parameter SQL with bind values for logging and debugging.
 *
 * Guidance: Use this output for diagnostics only; do not treat rendered SQL as an executable or perfectly lossless statement dump.
 *
 * Road-signs:
 * - emulate() skips positional-bind SQL and empty bind arrays
 * - parseBindValue() normalizes scalar and null values
 * - formatStringValue() applies optional truncation before quoting
 * - SqlCollector is the typical consumer for rendered SQL text
 *
 * @see \Switon\Db\PreparedEmulatorInterface
 * @see \Switon\Db\Inspector\SqlCollector
 */
class PreparedEmulator implements PreparedEmulatorInterface
{
    /** Quotes a string value for rendered SQL output. */
    protected function quote(string $value): string
    {
        return "'" . str_replace("'", "\\'", $value) . "'";
    }

    /** Normalizes a bind value to its rendered SQL representation. */
    protected function parseBindValue(mixed $value, int $preservedStrLength): int|string
    {
        return match (true) {
            is_string($value) => $this->formatStringValue($value, $preservedStrLength),
            is_int($value) => $value,
            $value === null => 'NULL',
            is_bool($value) => (int)$value,
            default => (string)$value // @codeCoverageIgnore - handles unexpected types (float, array, object)
        };
    }

    /** Applies optional length cap, then quotes the string. */
    protected function formatStringValue(string $value, int $preservedStrLength): string
    {
        $quoted = $this->quote($value);
        if ($preservedStrLength > 0 && strlen($quoted) >= $preservedStrLength) {
            return substr($quoted, 0, $preservedStrLength) . '...';
        }
        return $quoted;
    }

    /**
     * {@inheritDoc}
     *
     * Returns original SQL when bind is empty or uses positional indexes.
     */
    public function emulate(string $sql, array $bind, int $preservedStrLength = -1): string
    {
        if ($bind === [] || !is_string(array_key_first($bind))) {
            return $sql;
        }

        $replaces = [];
        foreach ($bind as $key => $value) {
            $replaces[':' . $key] = $this->parseBindValue($value, $preservedStrLength);
        }

        return strtr($sql, $replaces);
    }
}
