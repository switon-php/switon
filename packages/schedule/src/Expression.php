<?php

declare(strict_types=1);

namespace Switon\Schedule;

use DateTimeImmutable;
use DateTimeZone;

use function count;
use function preg_split;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Default {@see ExpressionInterface} implementation. Not POSIX crontab; exactly four space-separated fields.
 *
 * Guidance: Per-field token shape is enforced at {@see \Switon\Schedule\Schedule::register()} (same acceptance as {@see self::matches()}); no month or day-of-week — use task idempotency for “already ran this week” if needed.
 *
 * Components: second, minute, hour, day-of-month in the given <code>$timezoneId</code> (UTC when <code>null</code>), via <code>DateTimeImmutable</code>.
 *
 * Road-signs:
 * - matches
 * - four fields
 * - Schedule register
 * - TaskRunner
 *
 * @see \Switon\Schedule\ExpressionInterface
 * @see \Switon\Schedule\TaskRunner
 * @see \Switon\Schedule\Schedule::register()
 */
class Expression implements ExpressionInterface
{
    /** {@inheritDoc} */
    public function matches(string $expression, int $timestamp, ?string $timezoneId = null): bool
    {
        $parts = $this->split($expression);
        if (count($parts) !== 4) {
            return false;
        }

        return $this->matchFour($parts, $timestamp, $timezoneId ?? 'UTC');
    }

    /**
     * @param list<string> $parts
     * @param non-empty-string $timezoneId
     */
    protected function matchFour(array $parts, int $timestamp, string $timezoneId): bool
    {
        $tz = new DateTimeZone($timezoneId);
        $dt = (new DateTimeImmutable('@' . $timestamp))->setTimezone($tz);
        $second = (int)$dt->format('s');
        $minute = (int)$dt->format('i');
        $hour = (int)$dt->format('G');
        $dom = (int)$dt->format('j');

        return $this->fieldMatches($parts[0], $second)
            && $this->fieldMatches($parts[1], $minute)
            && $this->fieldMatches($parts[2], $hour)
            && $this->fieldMatches($parts[3], $dom);
    }

    protected function fieldMatches(string $field, int $value): bool
    {
        if ($field === '*') {
            return true;
        }
        if (str_starts_with($field, '*/')) {
            $step = (int)substr($field, 2);

            return $step > 0 && $value % $step === 0;
        }

        return (int)$field === $value;
    }

    /**
     * @return list<string>
     */
    protected function split(string $expression): array
    {
        $expression = trim($expression);
        $parts = preg_split('/\s+/', $expression, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];
        }

        /** @var list<string> */
        return $parts;
    }
}
