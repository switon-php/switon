<?php

declare(strict_types=1);

namespace Switon\Llm;

/**
 * Fills {@see LlmProvidersInterface} from YAML or DI config.
 *
 * @see \Switon\Llm\LlmDiscovery
 */
interface LlmDiscoveryInterface
{
    /**
     * Build one client per catalog route and {@see LlmProvidersInterface::add()} it.
     * A non-null `file` replaces `providers` / `aliases` first.
     */
    public function discover(): void;
}
