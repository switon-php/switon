<?php

declare(strict_types=1);

namespace Switon\Tooling;

/**
 * Loads `extra.switon.tools` (id → class) onto {@see ToolsInterface}. Existing ids stay.
 *
 * @see ToolDiscovery
 */
interface ToolDiscoveryInterface
{
    /** Register `extra.switon.tools` onto {@see ToolsInterface}. */
    public function discover(): void;
}
