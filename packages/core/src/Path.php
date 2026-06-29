<?php

declare(strict_types=1);

namespace Switon\Core;

use function explode;
use function file_exists;
use function getenv;
use function is_string;
use function ltrim;
use function preg_match;
use function preg_replace;
use function realpath;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtr;
use function substr;

/**
 * Lexical path helpers (no I/O except `resolve()`'s realpath).
 *
 * Relative paths reject NUL, absolute forms, and any <code>..</code> substring
 * (including <code>foo..bar</code>).
 *
 * @see \Switon\Core\PathAlias
 * @see \Switon\Core\Filesystem
 */
class Path
{
    /**
     * Normalize separators to <code>/</code>, collapse duplicate slashes, trim a
     * trailing slash (except the root <code>/</code>).
     *
     * A <code>scheme://</code> prefix (for example <code>phar://</code>) is kept verbatim and
     * only the remainder is normalized, so wrapper paths survive with their slashes intact.
     */
    public static function normalize(string $path): string
    {
        $prefix = '';
        if (($pos = strpos($path, '://')) !== false) {
            $prefix = substr($path, 0, $pos + 3);
            $path = substr($path, $pos + 3);
        }

        $path = strtr($path, '\\', '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $prefix . $path;
    }

    /**
     * Whether <code>$path</code> is absolute (leading <code>/</code>, UNC <code>\\</code>,
     * Windows drive <code>C:/</code> / <code>C:\</code>, or a <code>scheme://</code> wrapper).
     *
     * Not the inverse of `isRelative()` — safe-to-join relatives are a stricter subset.
     * Drive letters are recognized on every OS (same absolute-form stance as `isRelative()`).
     */
    public static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '\\\\') || strpos($path, '://') !== false) {
            return true;
        }

        $path = self::normalize($path);
        if (str_starts_with($path, '/')) {
            return true;
        }

        return preg_match('/^[A-Za-z]:\//', $path) === 1;
    }

    /**
     * Whether <code>$path</code> targets a phar archive entry.
     *
     * Kernel-level transfers such as Swoole <code>sendfile()</code> need a real file
     * descriptor, so phar paths must be read through PHP streams instead.
     */
    public static function isPhar(string $path): bool
    {
        return str_starts_with($path, 'phar://');
    }

    /**
     * Whether <code>$path</code> is a non-empty relative path safe to join under a root.
     *
     * Rejects NUL, absolute forms (<code>/</code>, drive letter, or <code>scheme://</code> wrapper),
     * any <code>..</code> substring, and <code>.</code> / empty path segments.
     */
    public static function isRelative(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..') || strpos($path, '://') !== false) {
            return false;
        }

        $path = self::normalize($path);
        if ($path === '' || $path === '.' || str_starts_with($path, '/')) {
            return false;
        }
        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                return false;
            }
        }

        return true;
    }

    /**
     * Absolute path, expanding a leading <code>~</code> / <code>~/</code> / <code>~\</code>.
     *
     * Raises when empty, relative, or an <code>@alias</code>. An unexpanded tilde
     * (home unset) also raises.
     */
    public static function absolute(string $path): string
    {
        $absolute = self::normalize(self::tilde($path));
        if (!self::isAbsolute($absolute)) {
            Exception::raise('Path must be absolute, got "{path}".', ['path' => $path]);
        }

        return $absolute;
    }

    /**
     * Expand a leading <code>~</code> / <code>~/</code> / <code>~\</code> to the
     * current-user home directory.
     *
     * Uses <code>HOME</code>, then <code>USERPROFILE</code>. When home is empty,
     * leaves the path unchanged.
     */
    public static function tilde(string $path): string
    {
        if ($path !== '~' && !str_starts_with($path, '~/') && !str_starts_with($path, '~\\')) {
            return $path;
        }

        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            $home = getenv('USERPROFILE');
        }
        if (!is_string($home) || $home === '') {
            return $path;
        }

        $home = rtrim(strtr($home, '\\', '/'), '/');
        if ($path === '~') {
            return $home;
        }

        return $home . '/' . ltrim(strtr(substr($path, 1), '\\', '/'), '/');
    }

    /**
     * Lexical <code>phar://</code> path with <code>.</code> segments removed.
     *
     * Returns null when <code>$path</code> is not a phar path or any segment is <code>..</code>.
     */
    public static function pharCanonical(string $path): ?string
    {
        if (!self::isPhar($path) || str_contains($path, "\0")) {
            return null;
        }

        $rest = substr(self::normalize($path), strlen('phar://'));
        $out = [];
        foreach (explode('/', $rest) as $index => $part) {
            if ($part === '..') {
                return null;
            }
            if ($part === '.') {
                continue;
            }
            if ($part === '' && $index !== 0) {
                continue;
            }
            $out[] = $part;
        }
        if ($out === [] || $out === ['']) {
            return null;
        }

        return 'phar://' . implode('/', $out);
    }

    /**
     * Resolve <code>$relative</code> under <code>$root</code> via realpath.
     *
     * Requires `isRelative()` for <code>$relative</code>. Returns null when
     * missing, not resolvable, or outside the root. When <code>$allowRoot</code>
     * is true, the resolved path may equal the root directory.
     *
     * Guidance: a <code>phar://</code> root is accepted when it has no <code>..</code>
     * segment and the joined entry exists.
     */
    public static function resolve(string $root, string $relative, bool $allowRoot = false): ?string
    {
        if (!self::isRelative($relative)) {
            return null;
        }

        if (self::isPhar($root)) {
            return self::resolvePhar($root, $relative, $allowRoot);
        }

        $realRoot = realpath($root);
        if ($realRoot === false) {
            return null;
        }

        $relative = self::normalize($relative);
        $candidate = rtrim(str_replace('\\', '/', $realRoot), '/') . '/' . $relative;
        $resolved = realpath($candidate);
        if ($resolved === false) {
            return null;
        }

        if (!self::within($realRoot, $resolved, $allowRoot)) {
            return null;
        }

        return $resolved;
    }

    protected static function resolvePhar(string $root, string $relative, bool $allowRoot): ?string
    {
        $realRoot = self::pharCanonical($root);
        if ($realRoot === null || !file_exists($realRoot)) {
            return null;
        }

        $candidate = $realRoot . '/' . self::normalize($relative);
        if (!file_exists($candidate) || !self::within($realRoot, $candidate, $allowRoot)) {
            return null;
        }

        return $candidate;
    }

    /**
     * Lexical under-root check (no realpath).
     *
     * Both sides are normalized. Used when realpath is unavailable (e.g. missing files).
     */
    public static function within(string $root, string $path, bool $allowRoot = true): bool
    {
        if (str_contains($root, "\0") || str_contains($path, "\0")) {
            return false;
        }

        $root = self::normalize($root);
        $path = self::normalize($path);
        if ($root === '' || $path === '') {
            return false;
        }

        if ($path === $root) {
            return $allowRoot;
        }

        if ($root === '/') {
            return str_starts_with($path, '/');
        }

        return str_starts_with($path, $root . '/');
    }

    /**
     * Absolute path relative to <code>$root</code>, or null when not within.
     *
     * Returns an empty string when <code>$absolute</code> equals <code>$root</code>.
     */
    public static function toRelative(string $root, string $absolute): ?string
    {
        if (!self::within($root, $absolute, true)) {
            return null;
        }

        $root = self::normalize($root);
        $absolute = self::normalize($absolute);
        if ($absolute === $root) {
            return '';
        }

        return substr($absolute, strlen($root) + 1);
    }
}
