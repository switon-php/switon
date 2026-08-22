<?php

declare(strict_types=1);

namespace Switon\Core;

use JsonSerializable;
use Stringable;
use Throwable;

use function bin2hex;
use function dirname;
use function implode;
use function is_array;
use function is_object;
use function is_scalar;
use function is_string;
use function mb_strlen;
use function mb_strcut;
use function mb_substr;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function random_bytes;
use function random_int;
use function realpath;
use function sprintf;
use function str_contains;
use function strlen;
use function str_replace;
use function strtr;
use function trim;

/**
 * Generic string helpers shared across components.
 *
 * Truncation: `cut()` is code-point (display) budget; `cutBytes()` is UTF-8
 * byte budget without splitting a code point. Both are for text — not opaque binary.
 * Whitespace: `squish()` collapses runs to a single space and trims.
 *
 * @see \Switon\Core\ClassName
 * @see \Switon\Core\Naming
 */
class Strings
{
    /**
     * Truncate by Unicode code points (display-oriented).
     *
     * When `$ellipsis` is non-empty, it counts toward `$maxChars`.
     */
    public static function cut(string $text, int $maxChars, string $ellipsis = ''): string
    {
        if ($maxChars <= 0) {
            return '';
        }
        if (mb_strlen($text, 'UTF-8') <= $maxChars) {
            return $text;
        }
        if ($ellipsis === '') {
            return mb_substr($text, 0, $maxChars, 'UTF-8');
        }
        $ellLen = mb_strlen($ellipsis, 'UTF-8');
        if ($ellLen >= $maxChars) {
            return mb_substr($ellipsis, 0, $maxChars, 'UTF-8');
        }

        return mb_substr($text, 0, $maxChars - $ellLen, 'UTF-8') . $ellipsis;
    }

    /**
     * Truncate by UTF-8 byte budget without splitting a code point.
     *
     * When `$ellipsis` is non-empty, it counts toward `$maxBytes`.
     */
    public static function cutBytes(string $text, int $maxBytes, string $ellipsis = ''): string
    {
        if ($maxBytes <= 0) {
            return '';
        }
        if (strlen($text) <= $maxBytes) {
            return $text;
        }
        if ($ellipsis === '') {
            return mb_strcut($text, 0, $maxBytes, 'UTF-8');
        }
        $ellBytes = strlen($ellipsis);
        if ($ellBytes >= $maxBytes) {
            return mb_strcut($ellipsis, 0, $maxBytes, 'UTF-8');
        }

        return mb_strcut($text, 0, $maxBytes - $ellBytes, 'UTF-8') . $ellipsis;
    }

    /**
     * Collapse runs of Unicode whitespace to a single space and trim ends.
     *
     * Newlines and tabs become spaces — use `lf()` when line structure must be kept.
     */
    public static function squish(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * Join elements into a chain string.
     *
     * @param array<string> $elements
     */
    public static function chain(array $elements, string $separator = ' -> ', ?string $append = null): string
    {
        if ($append !== null) {
            $elements[] = $append;
        }

        return implode($separator, $elements);
    }

    /**
     * Replace `{key}` placeholders with context values.
     *
     * @param array<string, mixed> $context
     */
    public static function interpolate(string|Stringable $message, array $context): string
    {
        $replaces = [];
        $messageStr = (string)$message;
        preg_match_all('#{([\w.]+)}#', $messageStr, $matches);
        foreach ($matches[1] as $key) {
            if (($val = $context[$key] ?? null) === null) {
                continue;
            }

            if (is_string($val)) {
                // no-op
            } elseif ($val instanceof JsonSerializable) {
                $val = Json::stringify($val);
            } elseif ($val instanceof Stringable) {
                $val = (string)$val;
            } elseif (is_scalar($val)) {
                $val = Json::stringify($val);
            } elseif (is_array($val)) {
                $val = Json::stringify($val);
            } elseif (is_object($val)) {
                $val = Json::stringify((array)$val);
            } else {
                continue;
            }

            $replaces['{' . $key . '}'] = $val;
        }
        return strtr($messageStr, $replaces);
    }

    /**
     * Render exception and previous chain for logs.
     */
    public static function renderException(Throwable $exception, ?string $root = null): string
    {
        $str = $exception::class . ': ' . $exception->getMessage() . PHP_EOL;
        $str .= '    at ' . $exception->getFile() . ':' . $exception->getLine() . PHP_EOL;
        $traces = $exception->getTraceAsString();
        $str .= preg_replace('/#\d+\s/', '    at ', $traces);

        $prev = $traces;
        $caused = $exception;
        while ($caused = $caused->getPrevious()) {
            $str .= PHP_EOL . '  Caused by ' . $caused::class . ': ' . $caused->getMessage() . PHP_EOL;
            $str .= '    at ' . $caused->getFile() . ':' . $caused->getLine() . PHP_EOL;
            $traces = $caused->getTraceAsString();
            if ($traces !== $prev) {
                $str .= preg_replace('/#\d+\s/', '    at ', $traces);
            } else {
                $str .= '    at ...';
            }
            $prev = $traces;
        }

        if ($root !== null && ($realRoot = realpath($root)) !== false) {
            $prefix = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, dirname($realRoot)) . DIRECTORY_SEPARATOR;
            $str = strtr($str, [$prefix => '']);
        }

        return str_replace(["\r\n", "\r"], "\n", $str);
    }

    /** Generate a random UUID v4 string (RFC 4122). */
    public static function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );
    }

    /**
     * Whether value is a path/book slug: letters, digits, hyphen, dot; length 1–128; not "." and no "..".
     */
    public static function isSlug(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z\d.-]{1,128}$/', $value)
            && $value !== '.'
            && !str_contains($value, '..');
    }

    /**
     * Safe single-segment file name from arbitrary text (call ids, labels, user input).
     *
     * Guidance: keeps `[A-Za-z0-9._-]`, collapses every other run to `_`, then trims leading and
     * trailing separators so the result cannot be `.` / `..` or start with a dot. Empty results fall
     * back to random hex, so callers always get a usable segment.
     */
    public static function fileName(string $value, int $fallbackBytes = 8): string
    {
        $leaf = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value) ?? '';
        $leaf = trim($leaf, '._-');

        return $leaf !== '' ? $leaf : bin2hex(random_bytes($fallbackBytes));
    }

    /**
     * Split on LF / CRLF / CR. Keeps blank lines; a trailing newline does not add an extra empty line.
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $lines = self::rawLines($text);
        if ($lines !== [] && $lines[array_key_last($lines)] === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * Split on LF / CRLF / CR without dropping a trailing empty segment.
     *
     * Empty input yields <code>['']</code> (same as <code>explode</code> / <code>preg_split</code> on newlines).
     * Prefer `lines()` when a trailing newline should not produce an extra empty line.
     *
     * @return list<string>
     */
    public static function rawLines(string $text): array
    {
        return explode("\n", self::lf($text));
    }

    /**
     * Normalize newlines to LF (for compare / edit before rewriting with `eol()`).
     */
    public static function lf(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * Primary newline in text: CRLF if present, else CR, else LF.
     */
    public static function eol(string $text): string
    {
        if (str_contains($text, "\r\n")) {
            return "\r\n";
        }

        if (str_contains($text, "\r")) {
            return "\r";
        }

        return "\n";
    }
}
