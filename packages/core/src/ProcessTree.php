<?php

declare(strict_types=1);

namespace Switon\Core;

use function ctype_digit;
use function exec;
use function function_exists;
use function implode;
use function microtime;
use function min;
use function posix_kill;
use function str_contains;
use function trim;
use function usleep;

use const PHP_OS_FAMILY;

/**
 * OS-specific process-tree stop, liveness, and `proc_close()` exit codes.
 *
 * Windows stops with `taskkill /T`. Unix signals descendants, then the pid.
 */
class ProcessTree
{
    /** First poll spacing while a signaled process tree is given time to exit. */
    protected const float POLL_SECONDS = 0.02;

    /**
     * Upper bound for one poll pause.
     *
     * Role: the liveness probe is not free on every OS — Windows runs `tasklist` per check — so a
     * fixed 20ms interval would spawn dozens of them over a three-second grace window. Backing off
     * keeps the fast path fast and the slow path cheap.
     */
    protected const float POLL_MAX_SECONDS = 0.25;

    /**
     * Stop `$pid` and its descendants.
     *
     * `$sleep` is the pause between polls for "did it exit", and the caller's own sleep so a
     * Swoole coroutine does not block the worker. Polling stops as soon as the tree is gone, so a
     * process that exits promptly costs a poll instead of the whole grace window.
     *
     * @param null|callable(float): void $sleep
     */
    public static function terminate(int $pid, float $graceSeconds = 0.1, ?callable $sleep = null): void
    {
        if ($pid <= 0) {
            return;
        }

        $sleep ??= static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) ($seconds * 1_000_000));
            }
        };

        if (PHP_OS_FAMILY === 'Windows') {
            if (!function_exists('exec')) {
                return;
            }
            // A taskkill without /F waits until the process exits. sleep() never does,
            // so the only kill that returns is the forced one, after the grace poll.
            if ($graceSeconds > 0) {
                self::awaitExit([$pid], $graceSeconds, $sleep);
            }
            if (self::alive($pid)) {
                @exec('taskkill /F /T /PID ' . $pid . ' >NUL 2>&1');
            }

            return;
        }

        $pids = self::descendants($pid);
        $pids[] = $pid;
        self::signal($pids, 15);
        if ($graceSeconds > 0) {
            self::awaitExit($pids, $graceSeconds, $sleep);
        }
        self::signal($pids, 9);
    }

    /**
     * Wait until every pid in `$pids` exited, or `$graceSeconds` ran out.
     *
     * Role: the graceful signal usually lands in milliseconds, and the forced kill should only
     * follow a process that actually ignored it. Sleeping the whole window regardless turns every
     * teardown into a fixed delay — on a Chrome that is already gone, three seconds of nothing.
     *
     * @param list<int> $pids
     * @param callable(float): void $sleep
     */
    protected static function awaitExit(array $pids, float $graceSeconds, callable $sleep): void
    {
        $deadline = microtime(true) + $graceSeconds;
        $poll = self::POLL_SECONDS;
        while (true) {
            $alive = false;
            foreach ($pids as $pid) {
                if (self::alive($pid)) {
                    $alive = true;
                    break;
                }
            }
            if (!$alive) {
                return;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return;
            }
            $pause = min($poll, $remaining);
            $sleep($pause);
            // Back off so a process that genuinely takes the whole window does not cost a probe
            // every 20ms.
            $poll = min(self::POLL_MAX_SECONDS, max(self::POLL_SECONDS, $pause * 2));
        }
    }

    /**
     * Whether `$pid` is still running.
     *
     * When the OS cannot be asked, the pid is treated as alive so a caller does not mistake
     * "unknown" for "already exited".
     */
    public static function alive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            if (!function_exists('exec')) {
                return true;
            }
            $output = [];
            exec('tasklist /FI "PID eq ' . $pid . '" /NH', $output);

            return str_contains(implode("\n", $output), (string) $pid);
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        if (!function_exists('exec')) {
            return true;
        }

        $output = [];
        exec('ps -p ' . $pid . ' >/dev/null 2>&1', $output, $code);

        return $code === 0;
    }

    /**
     * Map `proc_close()` to a process exit code.
     *
     * Windows (including Git Bash PHP) already returns the raw code. Unix may return a wait(2)
     * status with the code in the high byte. `$raw` forces a branch in tests.
     */
    public static function exitCode(int $closed, ?bool $raw = null): int
    {
        if ($closed === -1) {
            return -1;
        }

        $raw ??= PHP_OS_FAMILY === 'Windows';
        if ($raw) {
            return $closed;
        }

        return ($closed & 0xFF00) !== 0 ? ($closed >> 8) & 0xFF : $closed;
    }

    /**
     * @return list<int>
     */
    protected static function descendants(int $pid): array
    {
        $pids = [];
        foreach (self::childPids($pid) as $child) {
            $pids[] = $child;
            foreach (self::descendants($child) as $descendant) {
                $pids[] = $descendant;
            }
        }

        return $pids;
    }

    /**
     * @return list<int>
     */
    protected static function childPids(int $pid): array
    {
        if (!function_exists('exec')) {
            return [];
        }

        $output = [];
        @exec('pgrep -P ' . $pid . ' 2>/dev/null', $output);

        $children = [];
        foreach ($output as $line) {
            $line = trim($line);
            if ($line !== '' && ctype_digit($line)) {
                $children[] = (int) $line;
            }
        }

        return $children;
    }

    /**
     * @param list<int> $pids
     */
    protected static function signal(array $pids, int $signal): void
    {
        foreach ($pids as $pid) {
            if (function_exists('posix_kill')) {
                posix_kill($pid, $signal);
                continue;
            }

            if (function_exists('exec')) {
                @exec('kill -' . $signal . ' ' . $pid . ' 2>/dev/null');
            }
        }
    }
}
