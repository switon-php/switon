<?php

declare(strict_types=1);

namespace Switon\Search;

use Switon\Ai\PromptVariablesInterface;

/**
 * Site registry consumed by the `site_search` / `site_list` tools.
 */
interface SitesInterface extends PromptVariablesInterface
{
    /**
     * Every registered site, keyed by its id.
     *
     * @return array<string, Site>
     */
    public function all(): array;

    public function has(string $id): bool;

    /** @throws Exception When the id is unknown. */
    public function get(string $id): Site;

    /**
     * Register one site under its id, replacing any earlier row with that id.
     *
     * Use when a desk mounts its own site files after boot.
     */
    public function set(Site $site): void;

    /**
     * Parse one site file from disk.
     *
     * Use when a caller has a path rather than a registered id, such as a package's own fixture.
     *
     * @throws Exception When the file is empty.
     */
    public function load(string $file): Site;

    /** Rescan directories. Later files replace the same id. Rows already registered stay. */
    public function reload(): void;
}
