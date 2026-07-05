<?php

declare(strict_types=1);

namespace Switon\Console;

/**
 * Contract for in-place CLI progress output.
 *
 * Guidance: Treat progress output as transient console state; callers should pair start/advance with finish.
 *
 * Road-signs:
 * - Lifecycle: `start()` → `advance()`* → `finish()`; leaving a bar open corrupts the next frame
 * - Width: {@see AnsiWidthCalculator} measures display columns, not bytes
 * - Output: writes through {@see \Switon\Core\ConsoleInterface}; never raw `echo`
 */
interface ProgressBarInterface
{
    public function start(?int $max = null): static;

    public function advance(int $step = 1): static;

    public function setProgress(int $current): static;

    public function finish(): static;

    public function setFormat(string $format): static;

    public function setMessage(string $message): static;

    public function getCurrent(): int;

    public function getMax(): int;

    public function getPercent(): float;
}
