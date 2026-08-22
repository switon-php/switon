<?php

declare(strict_types=1);

namespace Switon\Search\Web;

/** One search engine. `buildUrl()` then `parse()` on the fetched body. */
interface EngineInterface
{
    /** Whether this engine accepts `site:host` on the query. */
    public function supportsSiteQuery(): bool;

    /** Preferred page size when the caller does not override per engine. */
    public function pageSize(): int;

    /** Build the SERP / API URL (may already include `site:` when `supportsSiteQuery()`). */
    public function buildUrl(string $query, int $maxResults, ?string $lang): string;

    /**
     * @return list<array{title: string, url: string, snippet: string}>
     */
    public function parse(string $body, int $maxResults): array;
}
