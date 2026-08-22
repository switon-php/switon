<?php

declare(strict_types=1);

namespace Switon\Search;

/**
 * Multi-engine open-web search (HTML scrape, no API keys).
 *
 * @see \Switon\Search\WebSearch
 */
interface WebSearchInterface
{
    /**
     * Empty query or an abort raises. Every engine failing returns an empty list.
     *
     * @return list<array{title: string, url: string, snippet: string, engine: string}>
     */
    public function search(
        string $query,
        int $maxResults,
        callable $shouldAbort,
        ?string $lang = null,
        ?string $site = null,
    ): array;
}
