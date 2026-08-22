<?php

declare(strict_types=1);

namespace Switon\Search;

/**
 * Runs one {@see Site}: request, fetch, map.
 *
 * @see \Switon\Search\SiteSearch
 */
interface SiteSearchInterface
{
    /**
     * Records on HTTP 200. A non-200 response is `results` plus `status`.
     *
     * @return list<array<string, string>>|array{results: list<array<string, string>>, status: int}
     */
    public function search(Site $site, string $query, int $maxResults, callable $shouldAbort, int $page = 1): array;
}
