<?php

declare(strict_types=1);

namespace Switon\Tooling;

use Switon\Ai\ToolSchema;

/**
 * Binds call arguments to one tool schema before the tool runs.
 *
 * Guidance: {@see ToolsInterface::call()} does not bind again.
 *
 * @see ArgumentBinder
 */
interface ArgumentBinderInterface
{
    /**
     * Apply defaults, then required, type, and value checks. One {@see \Switon\Tooling\Exception\ArgumentBindException}, one message per path. Wire fields such as `.caption` are skipped.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function bind(ToolSchema $schema, array $arguments): array;
}
