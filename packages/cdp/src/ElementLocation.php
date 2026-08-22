<?php

declare(strict_types=1);

namespace Switon\Cdp;

/**
 * Where an element is, and which document owns it.
 *
 * Role: the one thing {@see Page::click()} and {@see Page::fill()} need after addressing. A node in
 * the top document and a node in a cross-origin iframe differ in two ways — its point is composed
 * through the frame's offset, and its document answers to a different session — so both travel
 * together instead of as loose scalars.
 *
 * Guidance: produced by {@see Page::locate()}; not constructed by callers.
 */
final class ElementLocation
{
    /**
     * @param float $x Viewport x of the click point, already composed through any frame offset
     * @param float $y Viewport y of the click point, already composed through any frame offset
     * @param string $sessionId Owning session; empty means the tab's main session
     * @param array{x: float, y: float} $offset Frame content origin in the tab viewport
     * @param string $target The address as the caller wrote it, for messages
     */
    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly string $sessionId,
        public readonly array $offset,
        public readonly string $target,
    ) {
    }

    /** Whether the element lives in a cross-origin frame's own session. */
    public function isCrossOriginFrame(): bool
    {
        return $this->sessionId !== '';
    }
}
