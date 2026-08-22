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
use function html_entity_decode;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function max;
use function min;
use function parse_url;
use function rawurlencode;
use function rtrim;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;
use const LIBXML_NOERROR;
use const LIBXML_NONET;
use const LIBXML_NOWARNING;

/** Bing HTML SERP (`b_algo`). Honors `site:` in the product query language. */
class BingEngine implements EngineInterface
{
    #[Autowired] protected string $baseUrl = 'https://www.bing.com';
    #[Autowired] protected int $pageSize = 10;

    public function supportsSiteQuery(): bool
    {
        return true;
    }

    public function pageSize(): int
    {
        return max(1, min($this->pageSize, 50));
    }

    public function buildUrl(string $query, int $maxResults, ?string $lang): string
    {
        $url = rtrim($this->baseUrl, '/') . '/search?q=' . rawurlencode($query)
            . '&count=' . max(1, min($maxResults, 50));

        // setlang alone is not enough — without cc/mkt Bing often ranks JP dictionary
        // hits for bare CJK tokens like「股」. Prefer CN market for zh / default.
        $lang = $lang !== null && $lang !== '' ? str_replace('_', '-', $lang) : 'zh-CN';
        $url .= '&setlang=' . rawurlencode($lang);
        if (str_starts_with(strtolower($lang), 'zh')) {
            $url .= '&cc=CN&mkt=zh-CN';
        } else {
            $url .= '&mkt=' . rawurlencode($lang);
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
        $nodes = $xpath->query("//li[contains(concat(' ', normalize-space(@class), ' '), ' b_algo ')]");
        foreach ($nodes ?: [] as $node) {
            if (count($results) >= $maxResults) {
                break;
            }
            if (!$node instanceof DOMElement) {
                continue;
            }

            $linkNodes = $xpath->query('.//h2//a[@href]', $node);
            $link = $linkNodes ? $linkNodes->item(0) : null;
            if (!$link instanceof DOMElement) {
                continue;
            }

            $url = trim(html_entity_decode($link->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $title = UrlNormalizer::collapseWhitespace($link->textContent);
            if ($url === '' || $title === '' || str_starts_with(strtolower($url), 'javascript:')) {
                continue;
            }

            if ($this->isBingInternalUrl($url)) {
                continue;
            }

            $snippetNodes = $xpath->query(".//div[contains(@class, 'b_caption')]//p", $node);
            $snippetNode = $snippetNodes ? $snippetNodes->item(0) : null;
            $snippet = $snippetNode instanceof DOMElement
                ? UrlNormalizer::collapseWhitespace($snippetNode->textContent)
                : '';

            $results[] = [
                'title' => $title,
                'url' => $url,
                'snippet' => $snippet,
            ];
        }

        return $results;
    }

    protected function isBingInternalUrl(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if (str_starts_with($path, '/ck/a')) {
            return false;
        }

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host !== 'bing.com' && !str_ends_with($host, '.bing.com')) {
            return false;
        }

        return str_starts_with($path, '/search')
            || str_starts_with($path, '/images')
            || str_starts_with($path, '/videos')
            || $path === '/'
            || $path === '';
    }
}
