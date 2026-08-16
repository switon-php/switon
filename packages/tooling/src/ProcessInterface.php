<?php

declare(strict_types=1);

namespace Switon\Tooling;

use Switon\Ai\Event\ToolResultDelta;

/**
 * One process for a shell-like tool.
 *
 * Guidance: one instance per call. `make()` receives the agent and the call; cancel and spill use those.
 *
 * @see Process
 */
interface ProcessInterface
{
    /**
     * Newest bytes kept for `$pattern`. A pattern longer than this does not match.
     *
     * Guidance: matching stays independent of the stdout ceiling, so a hit past the cap is still visible.
     */
    public const int PATTERN_WINDOW_BYTES = 65536;

    /**
     * Open the process on this instance.
     *
     * @param list<string> $command
     * @param string $cwd Absolute working directory.
     * @param int $timeout Wall-clock cap in seconds.
     */
    public function start(array $command, string $cwd, int $timeout): static;

    /**
     * Read stdout and stderr until the process ends, the deadline hits, `$pattern` matches, or `$halt` returns true.
     *
     * `$delta` is dispatched per chunk with `chunk` and `channel` filled here.
     * Buffers are capped at the configured ceiling, then spilled for this call.
     * `$halt` sees the stdout collected so far after each chunk. True stops the process (`halted`).
     *
     * @param null|callable(string): bool $halt
     *
     * @return array{exit: int, stdout: string, stderr: string, timeout?: bool, cancelled?: bool, matched?: bool, halted?: bool, elapsed?: float}
     */
    public function output(ToolResultDelta $delta, ?string $pattern = null, ?callable $halt = null): array;

    /**
     * Collect stdout lines; stop after `$maxLines` (+1 to detect truncation).
     *
     * `$delta` is dispatched per chunk with `chunk` and `channel` filled here.
     * Stderr is spilled for this call.
     *
     * @return array{exit: int, lines: list<string>, truncated?: bool, timeout?: bool, cancelled?: bool, elapsed?: float, stderr?: string}
     */
    public function lines(ToolResultDelta $delta, int $maxLines): array;

    /**
     * Configured wall-clock cap in seconds.
     */
    public function timeout(): int;

    /**
     * In-memory stdout/stderr cap in bytes.
     */
    public function ceilingBytes(): int;

    /**
     * Coroutine-aware sleep: {@see \Swoole\Coroutine::sleep} inside a coroutine, else `usleep`.
     */
    public function wait(float $seconds): void;
}
