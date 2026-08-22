<?php

declare(strict_types=1);

namespace Switon\Search\Web;

/** Cookies and Referer for an engine whose endpoint is not a plain document GET. */
interface PageFetchHintsInterface
{
    /** Document to open first so the request carries that site's cookies, or null. */
    public function originUrl(): ?string;

    /** Referer for the API request, or null. */
    public function refererUrl(string $query, int $maxResults, ?string $lang): ?string;
}
