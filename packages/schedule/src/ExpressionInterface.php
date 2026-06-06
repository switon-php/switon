<?php

declare(strict_types=1);

namespace Switon\Schedule;

/**
 * Matches a four-field schedule string against a Unix timestamp; wall-clock components come from <code>$timezoneId</code> (IANA) or UTC when <code>null</code>. Subset rules in {@see Expression}.
 *
 * Road-signs:
 * - matches
 * - default impl Expression
 *
 * @see \Switon\Schedule\Expression
 * @see \Switon\Schedule\TaskRunner
 * @see \Switon\Schedule\Attribute\Scheduled
 */
interface ExpressionInterface
{
    /**
     * Returns whether <code>$expression</code> matches <code>$timestamp</code> in <code>$timezoneId</code> (UTC if <code>null</code>).
     *
     * @param non-empty-string|null $timezoneId
     */
    public function matches(string $expression, int $timestamp, ?string $timezoneId = null): bool;
}
