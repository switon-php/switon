<?php

declare(strict_types=1);

namespace Switon\Search;

/**
 * Downloads one URL and returns readable text, or the raw body when `$raw` is set.
 *
 * @see \Switon\Search\WebFetch
 */
interface WebFetchInterface
{
    /**
     * Fetch a URL and return readable page text (or raw body when `$raw`).
     *
     * @return array<string, mixed>
     */
    public function fetch(string $url, callable $shouldAbort, bool $raw = false): array;
}
