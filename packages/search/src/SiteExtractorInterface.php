<?php

declare(strict_types=1);

namespace Switon\Search;

/**
 * Turns one site response into rows using the site file's `item` spec.
 *
 * @see \Switon\Search\SiteExtractor
 */
interface SiteExtractorInterface
{
    /**
     * Rows from one HTML or JSON document. A field that misses every alternative is left out.
     *
     * @return list<array<string, string>>
     */
    public function extract(Site $site, string $document, string $documentUrl): array;
}
