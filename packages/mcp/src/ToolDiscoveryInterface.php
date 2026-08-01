<?php

declare(strict_types=1);

namespace Switon\Mcp;

/**
 * Optional filesystem discovery of {@see ToolInterface} classes into {@see ToolsInterface}.
 *
 * @see \Switon\Mcp\ToolDiscovery
 */
interface ToolDiscoveryInterface
{
    /**
     * Scan configured globs and {@see ToolsInterface::add()} missing names.
     * Explicit catalog entries win on name clash. Empty / missing globs are a no-op.
     */
    public function discover(): void;
}
