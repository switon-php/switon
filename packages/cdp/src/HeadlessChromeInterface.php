<?php

declare(strict_types=1);

namespace Switon\Cdp;

/**
 * Headless Chrome. Default port 59222, profile `~/.switon/chrome/headless`.
 *
 * Guidance: inject this for search and other silent fetches. Profile `~/.switon/chrome/headless`. {@see HeadedChromeInterface} uses a separate profile.
 *
 * Configure `port` and `userDataDir` on the headless implementation.
 */
interface HeadlessChromeInterface extends ChromeInterface
{
    /**
     * Send an HTTP request from this profile.
     *
     * `$origin` is the document to open first. Pass it when `$url` is an API on that site, so the call carries the site's cookies. Omit it for a document URL.
     *
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?string $origin = null,
    ): array;
}
