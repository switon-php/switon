<?php

declare(strict_types=1);

namespace Switon\Command;

/**
 * Contract for discovering available commands.
 *
 * Guidance: `discover()` returns a cached map only; it does not register commands with any runtime handler.
 *
 * Road-signs:
 * - map key is public command name
 * - map value is command class FQCN
 * - cache lives inside the discovery implementation
 *
 * @see \Switon\Command\CommandDiscovery
 */
interface CommandDiscoveryInterface
{
    /**
     * Returns the discovered command name to class map (cached).
     *
     * @return array<string, class-string> Command name => command class FQCN
     */
    public function discover(): array;
}
