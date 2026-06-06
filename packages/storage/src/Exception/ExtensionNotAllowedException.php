<?php

declare(strict_types=1);

namespace Switon\Storage\Exception;

use Switon\Storage\Exception;

/**
 * Thrown when a file extension is rejected by the configured allowlist.
 *
 * Use when `putFile()` receives a key whose extension is outside the permitted set.
 */
class ExtensionNotAllowedException extends Exception
{
    /**
     * HTTP status code for client misuse (Bad Request).
     */
    public function getStatusCode(): int
    {
        return 400;
    }
}
