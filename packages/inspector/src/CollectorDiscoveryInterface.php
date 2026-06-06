<?php

declare(strict_types=1);

namespace Switon\Inspector;

use Switon\Core\CollectorInterface;

/**
 * Defines collector discovery and instantiation contract.
 *
 * Use when collectors are contributed by package metadata and app configuration.
 *
 * @see \Switon\Inspector\CollectorDiscovery
 */
interface CollectorDiscoveryInterface
{
    /**
     * Resolves and instantiates all enabled collectors.
     *
     * @return array<string, CollectorInterface> Map of collector key to instance
     */
    public function resolve(): array;
}
