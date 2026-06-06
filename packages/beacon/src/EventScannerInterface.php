<?php

declare(strict_types=1);

namespace Switon\Beacon;

/**
 * Contract for discovering event class FQCNs for Beacon inspection tools.
 *
 * Guidance: Return event classes only; leave command formatting and payload inspection to Beacon commands.
 *
 * @see \Switon\Beacon\EventScanner
 * @see \Switon\Beacon\Command\EventCommand
 */
interface EventScannerInterface
{
    /**
     * Return discovered event class FQCNs.
     *
     * @return list<class-string>
     */
    public function scanEventClasses(): array;
}
