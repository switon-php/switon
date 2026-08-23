<?php

declare(strict_types=1);

namespace Switon\Agency;

/**
 * Registers package prompt variables onto {@see \Switon\Ai\PromptRendererInterface}.
 *
 * Guidance: package classes belong in composer.json `extra.switon.prompt.variables`.
 *
 * @see \Switon\Agency\PromptVariableDiscovery
 * @see \Switon\Ai\PromptVariablesInterface
 * @see \Switon\Agency\ServiceProvider
 */
interface PromptVariableDiscoveryInterface
{
    /** Register `extra.switon.prompt.variables` onto {@see \Switon\Ai\PromptRendererInterface}. */
    public function discover(): void;
}
