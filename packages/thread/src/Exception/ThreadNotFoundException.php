<?php

declare(strict_types=1);

namespace Switon\Thread\Exception;

use Switon\Core\NotFoundInterface;
use Switon\Thread\Exception;

/**
 * No thread leaf exists at the requested `datadir`.
 *
 * Guidance: {@see \Switon\Thread\ThreadsInterface::load()} raises this for a leaf that was never
 * created. Callers that own "load or create" decide it themselves — do not read a file-not-found
 * as "thread missing", and do not read this as a storage failure.
 *
 * @see \Switon\Thread\Threads::load()
 * @see \Switon\Core\NotFoundInterface
 */
class ThreadNotFoundException extends Exception implements NotFoundInterface
{
    public function getStatusCode(): int
    {
        return 404;
    }
}
