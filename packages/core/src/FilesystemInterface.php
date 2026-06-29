<?php

declare(strict_types=1);

namespace Switon\Core;

use Switon\Core\Exception\CreateDirectoryFailedException;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\RuntimeException;

/**
 * Filesystem contract with path-alias support.
 *
 * Paths may use aliases such as @app and are resolved before I/O.
 *
 * Road-signs:
 * - resolve aliases before I/O
 * - read/write/glob stay alias-aware
 *
 * @see \Switon\Core\Filesystem
 * @see \Switon\Core\PathAliasInterface
 */
interface FilesystemInterface
{
    /** Expand `~` and `@alias` to a concrete path. A relative path is returned unchanged. */
    public function resolve(string $path): string;

    /** Check whether a file or directory exists. */
    public function exists(string $path): bool;

    /** Get file size in bytes, or null when unavailable. */
    public function size(string $file): ?int;

    /**
     * Delete one file or wildcard-matched files.
     *
     * @throws RuntimeException
     */
    public function delete(string $path): void;

    /**
     * Read file content.
     *
     * @throws FileNotFoundException
     */
    public function read(string $file): string;

    /**
     * Read file as trimmed non-empty lines.
     *
     * @return list<string>
     *
     * @throws FileNotFoundException
     */
    public function lines(string $file): array;

    /**
     * Overwrite file content atomically (temp file + rename).
     *
     * Guidance: a crash mid-write keeps the previous content; readers never see a
     * truncated file. The temp sibling uses a random suffix on the same directory.
     *
     * @throws RuntimeException
     */
    public function write(string $file, string $data): void;

    /**
     * Append with `FILE_APPEND` (`O_APPEND`). Creates missing parent directories.
     *
     * One call is one write, appended as a single extent on a local filesystem.
     *
     * @throws RuntimeException
     */
    public function append(string $file, string $data): void;

    /**
     * Append one JSON value as a jsonl line ({@see Json::stringify()} + newline).
     *
     * @throws RuntimeException
     */
    public function appendJsonl(string $file, mixed $value): void;

    /**
     * Replace a jsonl file with these values, one compact line each.
     *
     * Guidance: an empty list writes an empty file.
     *
     * @param list<mixed> $values
     *
     * @throws RuntimeException
     */
    public function writeJsonl(string $file, array $values): void;

    /**
     * Read jsonl file ({@see Json::parse()} per non-empty line).
     *
     * A missing or non-file path returns an empty list.
     *
     * Guidance: a torn trailing line is dropped, and any earlier malformed line still throws.
     *
     * @return list<mixed>
     *
     * @throws FileNotFoundException when the file exists but cannot be read
     * @throws \Switon\Core\Exception\JsonException
     */
    public function readJsonl(string $file): array;

    /**
     * Read a JSON document ({@see Json::parse()}).
     *
     * A missing or non-file path returns null. An unreadable file or invalid JSON throws.
     *
     * @throws FileNotFoundException when the file exists but cannot be read
     * @throws \Switon\Core\Exception\JsonException
     */
    public function readJson(string $file): mixed;

    /**
     * Write a JSON document ({@see Json::stringify()} + trailing newline) via `write()`.
     *
     * @throws RuntimeException
     */
    public function writeJson(string $file, mixed $value, int $options = JSON_PRETTY_PRINT): void;

    /**
     * Move file or directory.
     *
     * @throws RuntimeException
     */
    public function move(string $src, string $dst, bool $overwrite = false): void;

    /** Check whether path is a directory. */
    public function isDir(string $dir): bool;

    /** Check whether path is a regular file. A symlink to a file counts. */
    public function isFile(string $file): bool;

    /**
     * Canonical absolute path, or null when it cannot be resolved.
     *
     * Disk symlinks are resolved. A <code>phar://</code> path is returned when it has no
     * <code>..</code> segment and the entry exists; <code>.</code> segments are removed.
     */
    public function realpath(string $path): ?string;

    /** Whether the path is readable. */
    public function isReadable(string $path): bool;

    /** Whether the path is a symbolic link. The link target is not followed. */
    public function isLink(string $path): bool;

    /**
     * Remove directory recursively.
     *
     * @throws RuntimeException
     */
    public function rmdir(string $dir): void;

    /**
     * Create directory recursively.
     *
     * @throws CreateDirectoryFailedException
     */
    public function mkdir(string $dir, int $mode = 0755): void;

    /**
     * Copy file or directory recursively.
     *
     * @throws RuntimeException
     */
    public function copy(string $src, string $dst, bool $overwrite = false): void;

    /**
     * Find paths by glob pattern.
     *
     * @return array<string>
     */
    public function glob(string $pattern, int $flags = 0): array;

    /**
     * List files in directory/pattern.
     *
     * @return list<string>
     */
    public function files(string $dir): array;

    /**
     * List directories in directory/pattern.
     *
     * @return list<string>
     */
    public function directories(string $dir): array;

    /**
     * List direct children names in a directory.
     *
     * @return array<string>
     *
     * @throws RuntimeException
     */
    public function list(string $dir, int $sorting_order = SCANDIR_SORT_ASCENDING): array;

    /** Get file mtime timestamp, or null when unavailable. */
    public function mtime(string $file): ?int;

    /** Set file mtime (and atime) to $time (default: now); also creates the file if missing. */
    public function touch(string $file, ?int $time = null): void;

    /**
     * Change file/directory mode.
     *
     * @throws RuntimeException
     */
    public function chmod(string $file, int $mode): void;
}
