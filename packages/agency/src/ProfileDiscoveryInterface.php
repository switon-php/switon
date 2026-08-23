<?php

declare(strict_types=1);

namespace Switon\Agency;

/**
 * Enumerates agent roots and hands each file to the profile and segment registries.
 *
 * Guidance: this class only finds files. {@see ProfilesInterface::load()} reads one profile,
 * {@see SegmentsInterface::load()} reads one segment — row shape stays in those registries.
 *
 * Roots are visited lowest precedence first: <code>extra.switon.agents</code>, then
 * <code>@agency/agents</code>, <code>@resources/agents</code>, <code>~/.switon/agents</code>.
 * A later file with the same id replaces the row. A root's <code>segments/</code> directory
 * is published the same way.
 *
 * @see \Switon\Agency\ProfileDiscovery
 * @see \Switon\Agency\ProfilesInterface::load()
 * @see \Switon\Agency\SegmentsInterface::load()
 */
interface ProfileDiscoveryInterface
{
    /**
     * Load every profile and flat segment under the configured roots.
     *
     * Guidance: rows already stored stay. A file seen again replaces that id. A file that
     * disappeared is kept until process restart.
     */
    public function discover(): void;
}
