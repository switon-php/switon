<?php

declare(strict_types=1);

namespace Switon\Storage\Exception;

/**
 * Requested object key does not exist or is not readable.
 *
 * Use when callers requested an object body or stream for a missing key.
 *
 * @see \Switon\Storage\BlobStoreInterface::get()
 * @see \Switon\Storage\BlobStoreInterface::readStream()
 */
class ObjectNotFoundException extends StorageException
{
}
