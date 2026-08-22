<?php

declare(strict_types=1);

namespace Switon\Core;

/**
 * Fetch one response from the web.
 *
 * Role: one raw response, `{status, headers, body}`. `origin` and `ready` are how a caller asks
 * for a site's cookies or a rendered DOM. A browser implementation applies them. The curl
 * implementation honors `timeout` and `should_abort` and ignores the other two.
 *
 * Guidance: the container binds this to {@see \Switon\Cdp\HeadlessChrome}. Inject
 * {@see \Switon\HttpClient\HttpClientInterface} when the call should go over curl. The contract
 * lives here so a caller can name the fetch without depending on the driver package.
 *
 * Guidance: this returns the raw response. To read a page as text, use
 * {@see \Switon\Search\Tool\WebFetchTool} on top of it.
 *
 * Options are a plain array keyed by the constants below, matching
 * {@see \Switon\HttpClient\RequestOptions}. Proxy, user agent, and profile stay container
 * configuration: they describe the deployment, not one call.
 */
interface WebFetcherInterface
{
    /** Overall timeout in seconds. */
    public const string TIMEOUT = 'timeout';

    /** Polled during the fetch; `true` abandons it. */
    public const string SHOULD_ABORT = 'should_abort';

    /**
     * Document to open before the request, so the call carries that site's cookies.
     *
     * Pass it when `$url` is an API on the site. Omit it for a document URL.
     */
    public const string ORIGIN = 'origin';

    /** CSS selector to wait for before capturing, so a rendered page is readable. */
    public const string READY = 'ready';

    /**
     * Send one request and return the response body.
     *
     * `$headers` are sent with the request; a `Referer` applies to this call only. `$body` is the
     * request payload for `POST`, `PUT`, and `PATCH`.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $options See the constants on this interface
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function fetch(
        string $url,
        string $method = 'GET',
        array $headers = [],
        ?string $body = null,
        array $options = [],
    ): array;
}
