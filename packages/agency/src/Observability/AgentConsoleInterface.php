<?php

declare(strict_types=1);

namespace Switon\Agency\Observability;

/**
 * Opt-in CLI transcript sink for agent runs.
 *
 * Guidance: Call `start()` once; duplicate register is ignored.
 *
 * @see AgentConsole
 */
interface AgentConsoleInterface
{
    /** Configure optional console detail levels before `start()`. */
    public function configure(bool $showPrompt = false, bool $rawTools = false): void;

    /** Register agent lifecycle listeners; safe to call more than once. */
    public function start(): void;
}
