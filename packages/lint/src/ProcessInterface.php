<?php

declare(strict_types=1);

namespace Switon\Lint;

/**
 * Sync command runner for lint drivers (no tooling dependency).
 *
 * @see \Switon\Lint\Process
 */
interface ProcessInterface
{
    /**
     * @param list<string> $command
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array;
}
