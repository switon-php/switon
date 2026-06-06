<?php

declare(strict_types=1);

namespace Switon\Storage;

use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\MisuseException;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Storage\Exception\ExtensionNotAllowedException;
use Switon\Storage\Exception\ObjectNotFoundException;
use Switon\Storage\Exception\StorageException;

use function array_filter;
use function array_map;
use function dirname;
use function explode;
use function fclose;
use function in_array;
use function is_resource;
use function pathinfo;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function stream_copy_to_stream;
use function strtolower;

/**
 * Filesystem-backed local blob store; object keys are relative paths under {@see $root} (path aliases allowed).
 *
 * Use when object storage can be backed by the local filesystem and Switon path aliases.
 *
 * Guidance: keys stay relative to `$root`; leading slashes and `..` path traversal segments are rejected.
 *
 * Road-signs:
 * - BlobStoreInterface contract
 * - FilesystemInterface for file operations
 * - PathAliasInterface for root resolution
 *
 * @see \Switon\Storage\BlobStoreInterface
 * @see \Switon\Core\FilesystemInterface
 * @see \Switon\Core\PathAliasInterface
 */
class BlobStore implements BlobStoreInterface
{
    #[Autowired] protected FilesystemInterface $filesystem;

    #[Autowired] protected PathAliasInterface $pathAlias;

    /** Root directory for all keys; supports aliases such as <code>@runtime/storage</code>. */
    #[Autowired] protected string $root = '@runtime/storage';

    /** {@inheritDoc} */
    public function exists(string $key): bool
    {
        return $this->filesystem->exists($this->path($key));
    }

    /** {@inheritDoc} */
    public function get(string $key): string
    {
        try {
            return $this->filesystem->read($this->path($key));
        } catch (FileNotFoundException) {
            ObjectNotFoundException::raise('Object not found for key "{key}".', ['key' => $key]);
        } catch (RuntimeException $e) {
            StorageException::raise('Failed to read object for key "{key}".', ['key' => $key], 0, $e);
        }
    }

    /** {@inheritDoc} */
    public function put(string $key, string $contents): void
    {
        $path = $this->path($key);
        $dir = $this->parentDir($path);
        try {
            if ($dir !== '') {
                $this->filesystem->mkdir($dir);
            }
            $this->filesystem->write($path, $contents);
        } catch (RuntimeException $e) {
            StorageException::raise('Failed to write object for key "{key}".', ['key' => $key], 0, $e);
        }
    }

    /** {@inheritDoc} */
    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (!$this->filesystem->exists($path)) {
            return;
        }
        try {
            $this->filesystem->delete($path);
        } catch (RuntimeException $e) {
            StorageException::raise('Failed to delete object for key "{key}".', ['key' => $key], 0, $e);
        }
    }

    /** {@inheritDoc} */
    public function size(string $key): ?int
    {
        return $this->filesystem->size($this->path($key));
    }

    /** {@inheritDoc} */
    public function readStream(string $key)
    {
        $path = $this->path($key);
        if (!$this->filesystem->exists($path)) {
            ObjectNotFoundException::raise('Object not found for key "{key}".', ['key' => $key]);
        }
        $resolved = $this->pathAlias->resolve($path);
        $handle = @fopen($resolved, 'rb');
        if ($handle === false) {
            StorageException::raise('Failed to open read stream for key "{key}".', ['key' => $key]);
        }

        return $handle;
    }

    /** {@inheritDoc} */
    public function writeStream(string $key, mixed $stream): void
    {
        if (!is_resource($stream)) {
            MisuseException::raise('writeStream() expects a stream resource.');
        }
        $path = $this->path($key);
        $dir = $this->parentDir($path);
        try {
            if ($dir !== '') {
                $this->filesystem->mkdir($dir);
            }
        } catch (RuntimeException $e) {
            StorageException::raise('Failed to write object for key "{key}".', ['key' => $key], 0, $e);
        }
        $resolved = $this->pathAlias->resolve($path);
        $out = @fopen($resolved, 'wb');
        if ($out === false) {
            StorageException::raise('Failed to open write stream for key "{key}".', ['key' => $key]);
        }
        try {
            $written = @stream_copy_to_stream($stream, $out);
            if ($written === false) {
                StorageException::raise('Stream copy failed for key "{key}".', ['key' => $key]);
            }
        } finally {
            fclose($out);
        }
    }

    /** {@inheritDoc} */
    public function putFile(string $key, string $filename, ?string $allowedExtensions = null): void
    {
        if ($allowedExtensions !== null) {
            $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
            $allowed = array_filter(array_map('trim', explode(',', strtolower($allowedExtensions))));
            if (!in_array($ext, $allowed, true)) {
                ExtensionNotAllowedException::raise('File extension "{ext}" is not allowed. Allowed: {allowed}', [
                    'ext' => $ext,
                    'allowed' => $allowedExtensions
                ]);
            }
        }

        $stream = @fopen($filename, 'rb');
        if ($stream === false) {
            StorageException::raise('Failed to open local file for reading: "{file}".', ['file' => $filename]);
        }

        try {
            $this->writeStream($key, $stream);
        } finally {
            fclose($stream);
        }
    }

    /** Joins {@see $root} and object key into a path accepted by {@see FilesystemInterface}. */
    protected function path(string $key): string
    {
        $key = $this->normalizeObjectKey($key);

        return rtrim($this->root, '/\\') . '/' . $key;
    }

    /**
     * @throws MisuseException
     */
    protected function normalizeObjectKey(string $key): string
    {
        $key = trim(str_replace('\\', '/', $key));
        if ($key === '') {
            MisuseException::raise('Object key must not be empty.');
        }
        if (str_starts_with($key, '/')) {
            MisuseException::raise('Object key must be relative, without a leading slash.');
        }
        if (str_contains($key, '..')) {
            MisuseException::raise('Object key must not contain ".." segments.');
        }

        return $key;
    }

    /** Returns the parent directory path used by mkdir (supports aliases in {@see $root}). */
    protected function parentDir(string $fullPath): string
    {
        $parent = dirname($fullPath);
        if ($parent === '.' || $parent === $fullPath) {
            return '';
        }

        return $parent;
    }
}
