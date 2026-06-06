<?php

declare(strict_types=1);

namespace Switon\Inspector\Collector;

/**
 * Request/coroutine-local storage for logger collector rows.
 *
 * @see \Switon\Inspector\Collector\LoggerCollector
 */
class LoggerCollectorContext
{
    /** @var array<int, array{time: string, level: string, category: string, file: string, line: int, message: string}> Inspector rows for current context. */
    public array $logs = [];
}
