<?php

declare(strict_types=1);

namespace Switon\Search;

use DOMDocument;
use Switon\Core\Attribute\Autowired;
use Switon\Core\WebFetcherInterface;
use Switon\Search\Web\UrlNormalizer;

use function count;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function is_string;
use function max;
use function min;
use function parse_url;
use function rawurlencode;
use function str_replace;
use function strtolower;
use function strtr;
use function trim;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_MAXREDIRS;
use const CURLOPT_NOPROGRESS;
use const CURLOPT_PROGRESSFUNCTION;
use const CURLOPT_PROTOCOLS_STR;
use const CURLOPT_PROXY;
use const CURLOPT_REDIR_PROTOCOLS_STR;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_USERAGENT;
use const LIBXML_NOERROR;
use const LIBXML_NONET;
use const LIBXML_NOWARNING;

/** Runs one site file. A non-200 response is `results` plus `status`. Transport failure throws. */
class SiteSearch implements SiteSearchInterface
{
    public const string DEFAULT_USER_AGENT = 'SwitonSearch/1.0 (+https://github.com/switon-php/search)';

    #[Autowired] protected SiteExtractorInterface $extractor;
    #[Autowired] protected WebFetcherInterface $chrome;

    #[Autowired] protected int $timeout = 15;
    #[Autowired] protected string $userAgent = self::DEFAULT_USER_AGENT;
    /** Proxy URL override; null uses env, empty string disables proxy. */
    #[Autowired] protected ?string $proxy = null;

    /**
     * @return list<array<string, string>>|array{results: list<array<string, string>>, status: int}
     */
    public function search(Site $site, string $query, int $maxResults, callable $shouldAbort, int $page = 1): array
    {
        $query = trim($query);
        if ($query === '') {
            Exception::raise('Search query must not be empty.');
        }

        $maxResults = max(1, min($maxResults, 50));
        if ($site->size > 0) {
            $maxResults = min($maxResults, $site->size);
        }
        $page = max(1, $page);
        $url = $this->requestUrl($site, $query, $page, $maxResults);
        $fetched = $this->fetchForSite($site, $url, $this->refererUrl($site, $query), $shouldAbort);
        if ($fetched['status'] !== 200) {
            return ['results' => [], 'status' => $fetched['status']];
        }
        $body = $fetched['body'];
        if ($site->kind === 'json') {
            $body = $this->jsonText($body);
        }

        $results = [];
        foreach ($this->extractor->extract($site, $body, $url) as $hit) {
            if (count($results) >= $maxResults) {
                break;
            }
            // A declared mapping needs a title and a link to be a result at all. A raw row has no
            // such contract, so it is kept as the source carried it.
            if ($site->item !== []) {
                $title = $hit['title'] ?? '';
                $link = $hit['url'] ?? '';
                if ($title === '' || $link === '') {
                    continue;
                }
            }
            $results[] = $hit;
        }

        return $results;
    }

    /**
     * Browser when `fetch` is `browser` or `ready` is set; otherwise curl.
     *
     * @param callable(): bool $shouldAbort
     *
     * @return array{body: string, status: int}
     */
    protected function fetchForSite(Site $definition, string $url, string $referer, callable $shouldAbort): array
    {
        if ($shouldAbort()) {
            Exception::raise('Site search aborted for "{url}".', ['url' => $url]);
        }

        if ($definition->fetch === 'browser' || $definition->ready !== '') {
            return $this->fetchInBrowser($definition, $url, $referer, $shouldAbort);
        }

        return $this->fetchWithCurl($url, $definition->kind === 'json' ? 'application/json' : 'text/html', $shouldAbort);
    }

    /**
     * Request URL for one page. `{keyword}` is percent-encoded; `{offset}`, `{limit}`, and `{page}`
     * are decimal.
     */
    protected function requestUrl(Site $site, string $keyword, int $page, int $maxResults): string
    {
        return strtr($site->request, [
            '{keyword}' => rawurlencode($keyword),
            '{offset}' => (string) (($page - 1) * $maxResults),
            '{limit}' => (string) $maxResults,
            '{page}' => (string) $page,
        ]);
    }

    /** Referer URL with `{keyword}` percent-encoded. */
    protected function refererUrl(Site $site, string $keyword): string
    {
        return str_replace('{keyword}', rawurlencode($keyword), $site->referer);
    }

    /**
     * Browser path: a document GET, a cookie-bearing request on the site's own document, or a
     * capture taken after `$ready` rendered.
     *
     * @param callable(): bool $shouldAbort
     *
     * @return array{body: string, status: int}
     */
    protected function fetchInBrowser(Site $definition, string $url, string $referer, callable $shouldAbort): array
    {
        $options = [
            WebFetcherInterface::TIMEOUT => (float) $this->timeout,
            WebFetcherInterface::SHOULD_ABORT => $shouldAbort,
        ];

        if ($definition->fetch === 'browser' && $referer !== '') {
            $origin = UrlNormalizer::origin($url);
            // Same-host APIs are fetched as a path. An absolute URL is what the WAF rejects.
            $options[WebFetcherInterface::ORIGIN] = $origin;

            return $this->chrome->fetch(
                $this->sameOriginPath($url, $origin),
                'GET',
                ['Referer' => $referer],
                null,
                $options,
            );
        }

        if ($definition->ready !== '') {
            $options[WebFetcherInterface::READY] = $definition->ready;
        }

        return $this->chrome->fetch($url, 'GET', [], null, $options);
    }

    /** Same-host APIs are fetched as a path. An absolute URL is what the WAF rejects. */
    protected function sameOriginPath(string $url, ?string $origin): string
    {
        if ($origin === null || $origin === '') {
            return $url;
        }
        $target = parse_url($url);
        $base = parse_url($origin);
        if (!is_array($target) || !is_array($base)) {
            return $url;
        }
        $targetHost = strtolower((string) ($target['host'] ?? ''));
        $baseHost = strtolower((string) ($base['host'] ?? ''));
        if ($targetHost === '' || $targetHost !== $baseHost || ($target['port'] ?? null) !== ($base['port'] ?? null)) {
            return $url;
        }

        $path = (string) ($target['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (isset($target['query'])) {
            $path .= '?' . $target['query'];
        }

        return $path;
    }

    /**
     * @param callable(): bool $shouldAbort
     *
     * @return array{body: string, status: int}
     */
    protected function fetchWithCurl(string $url, string $accept, callable $shouldAbort): array
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            Exception::raise('Site search rejected "{url}": only http and https are fetched.', ['url' => $url]);
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_PROTOCOLS_STR => 'HTTP,HTTPS',
            CURLOPT_REDIR_PROTOCOLS_STR => 'HTTP,HTTPS',
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => $this->userAgent !== '' ? $this->userAgent : self::DEFAULT_USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function (mixed $resource, float $downloadSize, float $downloaded, float $uploadSize, float $uploaded) use ($shouldAbort): int {
                return $shouldAbort() ? 1 : 0;
            },
        ];
        if ($shouldAbort()) {
            Exception::raise('Site search aborted for "{url}".', ['url' => $url]);
        }
        // null keeps the environment proxy; '' turns it off.
        if ($this->proxy !== null) {
            $options[CURLOPT_PROXY] = $this->proxy;
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        $errno = curl_errno($handle);
        if ($errno === 42) {
            Exception::raise('Site search aborted for "{url}".', ['url' => $url]);
        }
        if (!is_string($body) || $status === 0) {
            Exception::raise('Site search failed for "{url}": {error}.', [
                'url' => $url,
                'error' => $error !== '' ? $error : 'HTTP ' . $status,
            ]);
        }

        return ['body' => $body, 'status' => $status];
    }

    /** Curl returns raw JSON. Chrome may wrap that JSON in `<pre>`. */
    protected function jsonText(string $body): string
    {
        $trimmed = ltrim($body);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            return $trimmed;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($dom->getElementsByTagName('pre') as $pre) {
            $text = trim($pre->textContent);
            if ($text !== '' && ($text[0] === '{' || $text[0] === '[')) {
                return $text;
            }
        }

        return $body;
    }
}
