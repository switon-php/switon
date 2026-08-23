<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Ai\ExtensionInterface;

/**
 * Registry of {@see ExtensionInterface} hooks for one host.
 *
 * Itself an extension, so the agent and loop talk to one object: lifecycle calls fan out to
 * every registered row, and the registry adds discovery on top.
 *
 * Road-signs:
 * - `extra.switon.agent.extensions`: package extensions (composer metadata)
 * - scan paths: app extensions, `@app/Agent/Extension/*Extension.php`
 * - order: composer entries first, then scan hits; duplicates keep the first
 *
 * Guidance: a host with no extensions resolves to an empty registry, and every lifecycle point
 * becomes a no-op.
 */
interface ExtensionsInterface extends ExtensionInterface
{
    /**
     * Registry rows in call order.
     *
     * @return list<ExtensionInterface>
     */
    public function all(): array;

    /** Re-read package metadata and scan paths, replacing the current rows. */
    public function discover(): void;
}
