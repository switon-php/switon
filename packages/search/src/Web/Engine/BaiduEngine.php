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
use function rawurlencode;
use function rtrim;
use function str_starts_with;
use function strtolower;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;
use const LIBXML_NOERROR;
use const LIBXML_NONET;
use const LIBXML_NOWARNING;

/** Baidu HTML SERP (`c-container`). Uses `rn=` for page size (20 works). */
class BaiduEngine implements EngineInterface
{
    #[Autowired] protected string $baseUrl = 'https://www.baidu.com';
    #[Autowired] protected int $pageSize = 20;

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
        return rtrim($this->baseUrl, '/') . '/s?wd=' . rawurlencode($query)
            . '&rn=' . max(1, min($maxResults, 50));
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
        $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' c-container ')]");
        foreach ($nodes ?: [] as $node) {
            if (count($results) >= $maxResults) {
                break;
            }
            if (!$node instanceof DOMElement) {
                continue;
            }

            $linkNodes = $xpath->query('.//h3//a[@href]', $node);
            $link = $linkNodes ? $linkNodes->item(0) : null;
            if (!$link instanceof DOMElement) {
                continue;
            }

            $url = trim(html_entity_decode($link->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $title = UrlNormalizer::collapseWhitespace($link->textContent);
            if ($url === '' || $title === '' || str_starts_with(strtolower($url), 'javascript:')) {
                continue;
            }

            $snippetNodes = $xpath->query(".//*[contains(@class, 'c-abstract') or contains(@class, 'content-right_')][1]", $node);
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
}
