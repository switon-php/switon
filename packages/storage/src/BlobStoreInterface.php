<?php

declare(strict_types=1);

namespace Switon\Storage;

/**
 * Local binary object access in a single logical namespace (root path held by the implementation).
 *
 * Keys are relative paths under that root for {@see BlobStore}; HTTP URLs and remote backends are out of scope for this package.
 *
 * Road-signs:
 * - get()/put()/delete() for whole-object operations
 * - readStream()/writeStream() for stream I/O
 * - putFile() for local upload-style writes
 * - ObjectNotFoundException / StorageException
 *
 * @see \Switon\Storage\BlobStore
 * @see \Switon\Storage\Exception\ObjectNotFoundException
 * @see \Switon\Storage\Exception\StorageException
 */
interface BlobStoreInterface
{
    /** Whether an object exists for the given key. */
    public function exists(string $key): bool;

    /**
     * Read the full object body.
     *
     * @throws \Switon\Storage\Exception\ObjectNotFoundException
     */
    public function get(string $key): string;

    /**
     * Write or replace an object.
     *
     * @throws \Switon\Storage\Exception\StorageException
     */
    public function put(string $key, string $contents): void;

    /**
     * Remove an object. Implementations may treat a missing key as success or throw; the choice must be documented per adapter.
     *
     * @throws \Switon\Storage\Exception\StorageException
     */
    public function delete(string $key): void;

    /** Byte length of the object, or null when unknown or missing. */
    public function size(string $key): ?int;

    /**
     * Open a read-only stream; the caller must close the resource.
     *
     * @return resource
     *
     * @throws \Switon\Storage\Exception\ObjectNotFoundException
     * @throws \Switon\Storage\Exception\StorageException
     */
    public function readStream(string $key);

    /**
     * Write from a stream source.
     *
     * @param resource $stream
     *
     * @throws \Switon\Storage\Exception\StorageException
     */
    public function writeStream(string $key, mixed $stream): void;

    /**
     * Write an object from a local file path.
     *
     * This is a convenience method that handles opening and closing the stream.
     * If $allowedExtensions is provided (e.g. "jpg,png,gif"), the extension of the $key is validated.
     *
     * @throws \Switon\Storage\Exception\StorageException
     * @throws \Switon\Storage\Exception\ExtensionNotAllowedException
     */
    public function putFile(string $key, string $filename, ?string $allowedExtensions = null): void;
}
