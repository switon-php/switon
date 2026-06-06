<?php

declare(strict_types=1);

namespace Switon\Storage;

/**
 * Base exception for the storage component.
 *
 * Use when storage-specific failures need one package-level exception root.
 *
 * @see \Switon\Storage\Exception\StorageException
 * @see \Switon\Storage\Exception\ObjectNotFoundException
 */
class Exception extends \Switon\Core\Exception
{
}
