<?php

declare(strict_types=1);

namespace Switon\Beacon;

use Switon\Beacon\Exception\ClassIndexNotFoundException;

/**
 * AI-facing class index: returns project FQCNs (app + vendor).
 *
 * Guidance: Prefer this contract for AI discovery flows that need a project-wide class list; callers should handle stale Composer classmaps as a recoverable setup issue.
 *
 * @see \Switon\Beacon\ClassIndex
 */
interface ClassIndexInterface
{
    /**
     * All FQCNs (app + vendor, tests and require-dev excluded).
     *
     * @return list<class-string>
     *
     * @throws ClassIndexNotFoundException When the Composer class index is unavailable or stale
     */
    public function all(): array;

    /**
     * Number of vendor FQCNs from the Composer classmap.
     *
     * A very small value usually means the optimized classmap should be regenerated.
     */
    public function getVendorClassCount(): int;
}
