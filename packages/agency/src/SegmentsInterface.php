<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Ai\Segment;

/**
 * Named {@see Segment} grants for {@see \Switon\Agency\ChartersInterface::get()} and catalog expand.
 *
 * Guidance: this registry has no paths of its own. Flat segments live in each agent root's
 * `segments/` subdirectory and are handed here by {@see ProfileDiscoveryInterface::discover()};
 * desk `agents/segments/` arrive the same way from {@see \Switon\Desk\Desks::load()}, already
 * namespaced as <code>{deskId}.{leaf}</code>. Ids are kept as written, and a later source
 * replaces the same id.
 *
 * @see Segments
 */
interface SegmentsInterface
{
    public function get(string $id): Segment;

    public function has(string $id): bool;

    /** Last write wins. */
    public function set(Segment $segment): void;

    /**
     * @return array<string, Segment> Segment id => row
     */
    public function all(): array;

    /** Load one markdown segment file; null when it has no readable frontmatter. */
    public function load(string $file): ?Segment;
}
