<?php

declare(strict_types=1);

namespace Switon\Storage\Exception;

use Switon\Storage\Exception;

/**
 * Storage operation failure; catch subclasses for not-found or disk errors.
 *
 * Use when a backend read/write/delete/stream operation cannot complete normally.
 *
 * Road-signs:
 * - ObjectNotFoundException for missing keys
 *
 * @see \Switon\Storage\BlobStoreInterface
 */
class StorageException extends Exception
{
}
