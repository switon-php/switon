<?php

declare(strict_types=1);

namespace Switon\Inspector;

/**
 * Defines lifecycle contract for the inspector coordinator.
 *
 * Guidance:
 * - boot this once during application startup
 * - let the implementation coordinate collectors, listeners, and storage integration
 *
 * @see \Switon\Inspector\Inspector
 */
interface InspectorInterface
{
    /**
     * Boots inspector runtime listeners and collector integrations.
     */
    public function boot(): void;
}
