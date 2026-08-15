<?php

declare(strict_types=1);

namespace Switon\Ai;

/**
 * Supplies template keys for one prompt render.
 *
 * Guidance: list the class in composer.json `extra.switon.prompt.variables`; discovery registers it at boot.
 *
 * @see PromptRendererInterface::registerVariables()
 */
interface PromptVariablesInterface
{
    /**
     * @return array<string, mixed>
     */
    public function variables(AgentInterface $agent): array;
}
