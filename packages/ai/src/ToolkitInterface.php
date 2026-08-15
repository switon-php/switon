<?php

declare(strict_types=1);

namespace Switon\Ai;

/**
 * Related tools that name themselves.
 *
 * Road-signs:
 * - `schema()` returns `null` = this toolkit does not claim `$name` — that is the only dispatch signal. Wire ids are complete (`agent_file_research`); the host adds no prefix
 * - Registration label: {@see \Switon\Tooling\ToolsInterface::addToolkit()} takes a name for removal/debug only, never a prefix
 * - Only a catch-all toolkit may claim by pattern; every other implementation is an exact lookup
 *
 * @see \Switon\Tooling\ToolsInterface
 * @see \Switon\Ai\ToolInterface
 */
interface ToolkitInterface extends ToolInterface
{
    /** Refresh the catalog; host owns add/remove. */
    public function reload(): void;
}
