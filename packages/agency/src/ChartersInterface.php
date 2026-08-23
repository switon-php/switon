<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Ai\AgentInterface;
use Switon\Ai\Charter;

/**
 * Assembles a {@see Charter} for one agent.
 *
 * Road-signs:
 * - Sources: the agent's profile prompt/tools + skills
 * - Prompt text: {@see \Switon\Ai\PromptRendererInterface}
 * - Tools: {@see \Switon\Tooling\ToolsInterface::pick()} for the delivery's name list
 * - Staleness: `Charter::invalidate()`; the loop invalidates after a fold
 *
 * @see Charters
 */
interface ChartersInterface
{
    /** Charter for this agent: its profile, root-ness, and desk-owned segment refs. */
    public function get(AgentInterface $agent): Charter;
}
