<?php

declare(strict_types=1);

namespace Switon\Search\Web\Engine;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Switon\Core\Attribute\Autowired;
use Switon\Search\Web\EngineInterface;
use Switon\Search\Web\UrlNormalizer;

use function count;
use function explode;
use function html_entity_decode;
use function is_string;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function ltrim;
use function max;
use function min;
use function parse_url;
use function preg_match;
use function preg_split;
use function rawurldecode;
use function rawurlencode;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strpos;
use function strtolower;
use function substr;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;
use const LIBXML_NOERROR;
use const LIBXML_NONET;
use const LIBXML_NOWARNING;

/** Google HTML SERP. `/goto` stays unresolved; the cite host is the attribution. */
class GoogleEngine implements EngineInterface
{
    #[Autowired] protected string $baseUrl = 'https://www.google.com.hk';
    #[Autowired] protected int $pageSize = 10;

    public function supportsSiteQuery(): bool
    {
        return true;
    }

    public function pageSize(): int
    {
        return max(1, min($this->pageSize, 20));
    }

    public function buildUrl(string $query, int $maxResults, ?string $lang): string
    {
        $url = rtrim($this->baseUrl, '/') . '/search?q=' . rawurlencode($query)
            . '&num=' . max(1, min($maxResults, 20));
        if ($lang !== null && $lang !== '') {
            $url .= '&hl=' . rawurlencode(str_replace('_', '-', $lang));
        }

        return $url;
    }

    public function parse(string $body, int $maxResults): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            return $this->parseDocument($body, $maxResults);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return list<array{title: string, url: string, snippet: string}> */
    protected function parseDocument(string $body, int $maxResults): array
    {
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $xpath = new DOMXPath($dom);

        $results = [];
        $seen = [];

        $links = $xpath->query('//a[.//h3][@href]');
        foreach ($links ?: [] as $link) {
            if (count($results) >= $maxResults) {
                break;
            }
            if (!$link instanceof DOMElement) {
                continue;
            }

            $h3Nodes = $xpath->query('.//h3', $link);
            $h3 = $h3Nodes ? $h3Nodes->item(0) : null;
            $title = UrlNormalizer::collapseWhitespace($h3 instanceof DOMElement ? $h3->textContent : '');
            if ($title === '') {
                continue;
            }

            $container = $this->resultContainer($link);
            $url = $this->resolveResultUrl($link, $xpath, $container);
            if ($url === '' || $this->isGoogleInternalUrl($url)) {
                continue;
            }

            $key = UrlNormalizer::key($url);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $results[] = [
                'title' => $title,
                'url' => $url,
                'snippet' => $this->extractSnippet($xpath, $container ?? $link),
            ];
        }

        return $results;
    }

    /**
     * Prefer plaintext href / `/url?q=http…`. For `/goto?` use cite host only
     * (no path rebuild, no redirect resolve).
     */
    protected function resolveResultUrl(DOMElement $link, DOMXPath $xpath, ?DOMElement $container): string
    {
        $href = trim(html_entity_decode($link->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $url = $this->normalizeHref($href);
        if ($url !== '') {
            return $url;
        }

        $citeRoot = $container ?? $link;
        $cites = $xpath->query('.//cite', $citeRoot);
        if (!$cites || $cites->length === 0) {
            $cites = $xpath->query('.//cite', $link);
        }
        foreach ($cites ?: [] as $cite) {
            if (!$cite instanceof DOMElement) {
                continue;
            }
            $text = UrlNormalizer::collapseWhitespace($cite->textContent);
            $origin = $this->originFromCite($text);
            if ($origin !== '') {
                return $origin;
            }
        }

        return '';
    }

    /** First breadcrumb segment only — host / origin, never path pieces after `›`. */
    protected function originFromCite(string $citeText): string
    {
        $citeText = trim($citeText);
        if ($citeText === '') {
            return '';
        }

        $parts = preg_split('/\s*[›>]\s*/u', $citeText);
        $base = is_string($parts[0] ?? null) ? trim($parts[0]) : '';
        if ($base === '') {
            return '';
        }

        if (preg_match('#https?://[^\s›>]+#i', $base, $m) === 1) {
            $candidate = rtrim($m[0], '.,;）)/');
            $scheme = strtolower((string) (parse_url($candidate, PHP_URL_SCHEME) ?? ''));
            $host = (string) (parse_url($candidate, PHP_URL_HOST) ?? '');
            if (($scheme === 'http' || $scheme === 'https') && $host !== '') {
                return $scheme . '://' . $host;
            }
        }

        if (preg_match('#^(?:www\.)?[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$#i', $base) === 1) {
            return 'https://' . ltrim($base, '/');
        }

        return '';
    }

    protected function resultContainer(DOMElement $link): ?DOMElement
    {
        $node = $link;
        for ($i = 0; $i < 12; $i++) {
            $parent = $node->parentNode;
            if (!$parent instanceof DOMElement) {
                return null;
            }
            $class = ' ' . $parent->getAttribute('class') . ' ';
            if (str_contains($class, ' N54PNb ')
                || str_contains($class, ' tF2Cxc ')
                || str_contains($class, ' MjjYud ')
                || str_contains($class, ' g ')
            ) {
                return $parent;
            }
            $node = $parent;
        }

        return null;
    }

    protected function extractSnippet(DOMXPath $xpath, DOMElement $scope): string
    {
        $nodes = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' VwiC3b ')]", $scope);
        $first = $nodes ? $nodes->item(0) : null;
        if (!$first instanceof DOMElement) {
            return '';
        }

        return UrlNormalizer::collapseWhitespace($first->textContent);
    }

    protected function normalizeHref(string $href): string
    {
        if ($href === '' || str_starts_with(strtolower($href), 'javascript:')) {
            return '';
        }

        $absolute = str_starts_with($href, 'http://') || str_starts_with($href, 'https://');
        $probe = $absolute ? $href : (str_starts_with($href, '/') ? 'https://www.google.com' . $href : '');
        if ($probe === '') {
            return '';
        }

        $host = strtolower((string) (parse_url($probe, PHP_URL_HOST) ?? ''));
        $path = (string) (parse_url($probe, PHP_URL_PATH) ?? '');
        $onGoogle = $host === '' || $this->isGoogleHost($host);
        if ($onGoogle && str_starts_with($path, '/goto')) {
            return '';
        }
        if ($onGoogle && str_starts_with($path, '/url')) {
            $query = (string) (parse_url($probe, PHP_URL_QUERY) ?? '');
            $q = $this->rawQuery($query, 'q');
            if ($q === '') {
                $q = $this->rawQuery($query, 'url');
            }
            $q = trim($q);
            if (str_starts_with(strtolower($q), 'http://') || str_starts_with(strtolower($q), 'https://')) {
                return $q;
            }

            return '';
        }

        return $absolute ? $href : '';
    }

    /** One query value, without turning a literal `+` into a space. */
    protected function rawQuery(string $query, string $name): string
    {
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            $key = rawurldecode($eq === false ? $pair : substr($pair, 0, $eq));
            if ($key !== $name) {
                continue;
            }

            return rawurldecode($eq === false ? '' : substr($pair, $eq + 1));
        }

        return '';
    }

    protected function isGoogleHost(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === 'google') {
            return true;
        }

        return preg_match('/(?:^|\.)google\.(?:[a-z]{2,3})(?:\.[a-z]{2})?$/', $host) === 1;
    }

    protected function isGoogleInternalUrl(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '' || !$this->isGoogleHost($host)) {
            return false;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return str_starts_with($path, '/search')
            || str_starts_with($path, '/url')
            || str_starts_with($path, '/goto')
            || str_starts_with($path, '/httpservice')
            || $path === '/'
            || $path === '';
    }
}
