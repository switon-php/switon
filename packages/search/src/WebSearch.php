<?php

declare(strict_types=1);

namespace Switon\Search;

use Switon\Core\Attribute\Autowired;
use Switon\Core\WebFetcherInterface;
use Switon\Search\Exception;
use Switon\Search\Web\Engine\BaiduEngine;
use Switon\Search\Web\Engine\BingEngine;
use Switon\Search\Web\Engine\GoogleEngine;
use Switon\Search\Web\Engine\ZhihuEngine;
use Switon\Search\Web\EngineInterface;
use Switon\Search\Web\PageFetchHintsInterface;
use Switon\Search\Web\UrlNormalizer;
use Throwable;

use function array_fill_keys;
use function array_keys;
use function count;
use function max;
use function min;
use function preg_match;
use function preg_quote;
use function str_contains;
use function strtolower;
use function substr;
use function trim;

/** Runs each engine in order, then dedupes normalized URLs. A failed engine is skipped. */
class WebSearch implements WebSearchInterface
{
    /**
     * @var array<string, EngineInterface>
     */
    #[Autowired(instances: true)] protected array $engines = [
        'baidu' => BaiduEngine::class,
        'google' => GoogleEngine::class,
        'bing' => BingEngine::class,
        'zhihu' => ZhihuEngine::class,
    ];

    #[Autowired] protected WebFetcherInterface $chrome;

    /** Per-engine fetch timeout in seconds. */
    #[Autowired] protected int $timeout = 15;

    public function search(
        string $query,
        int $maxResults,
        callable $shouldAbort,
        ?string $lang = null,
        ?string $site = null,
    ): array {
        $query = trim($query);
        if ($query === '') {
            Exception::raise('Search query must not be empty.');
        }

        $lang = $lang !== null ? trim($lang) : null;
        if ($lang === '') {
            $lang = null;
        }

        $siteHost = $site !== null && trim($site) !== '' ? UrlNormalizer::siteHost($site) : '';
        // Host ceiling: enough room for all engine pages after dedupe (no extra quality filter).
        $maxResults = max(1, min($maxResults, 80));

        /** @var array<string, list<array{title: string, url: string, snippet: string}>> $byEngine */
        $byEngine = [];
        foreach ($this->engines as $id => $engine) {
            if ($shouldAbort()) {
                Exception::raise('Web search aborted for query "{query}".', ['query' => $query]);
            }

            $pageSize = $engine->pageSize();
            $engineQuery = $engine->supportsSiteQuery()
                ? $this->applySiteFilter($query, $siteHost)
                : $query;
            $url = $engine->buildUrl($engineQuery, $pageSize, $lang);

            try {
                $headers = [];
                $options = [
                    WebFetcherInterface::TIMEOUT => $this->timeout,
                    WebFetcherInterface::SHOULD_ABORT => $shouldAbort,
                ];
                if ($engine instanceof PageFetchHintsInterface) {
                    $referer = $engine->refererUrl($engineQuery, $pageSize, $lang);
                    if ($referer !== null && trim($referer) !== '') {
                        $headers['Referer'] = $referer;
                    }
                    $options[WebFetcherInterface::ORIGIN] = $engine->originUrl();
                }
                $response = $this->chrome->fetch($url, 'GET', $headers, null, $options);
            } catch (Throwable) {
                if ($this->aborted($shouldAbort)) {
                    Exception::raise('Web search aborted for query "{query}".', ['query' => $query]);
                }
                continue;
            }

            if ($this->aborted($shouldAbort)) {
                Exception::raise('Web search aborted for query "{query}".', ['query' => $query]);
            }
            if ($response['status'] < 200 || $response['status'] >= 300 || $response['body'] === '') {
                continue;
            }
            $body = $response['body'];

            if ($this->looksLikeChallenge($body)) {
                continue;
            }

            $parsed = $engine->parse($body, $pageSize);
            if ($parsed !== []) {
                $byEngine[$id] = $parsed;
            }
        }

        if ($byEngine === []) {
            return [];
        }

        return $this->merge($byEngine, $maxResults);
    }

    /** Captcha / bot interstitial — no organic results worth parsing. */
    protected function looksLikeChallenge(string $body): bool
    {
        $sample = strtolower(substr($body, 0, 8000));
        foreach ([
            '百度安全验证',
            'our systems have detected unusual traffic',
            'cf-browser-verification',
            'attention required! | cloudflare',
            '/sorry/index',
            'g-recaptcha',
        ] as $marker) {
            if (str_contains($sample, strtolower($marker))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Round-robin merge across engines, skipping duplicate normalized URLs.
     *
     * @param array<string, list<array{title: string, url: string, snippet: string}>> $byEngine
     *
     * @return list<array{title: string, url: string, snippet: string, engine: string}>
     */
    protected function merge(array $byEngine, int $maxResults): array
    {
        $merged = [];
        $seen = [];
        $indexes = array_fill_keys(array_keys($byEngine), 0);

        while (count($merged) < $maxResults) {
            $progress = false;
            foreach ($indexes as $engineId => $index) {
                $list = $byEngine[$engineId];
                if ($index >= count($list)) {
                    continue;
                }

                $progress = true;
                $indexes[$engineId] = $index + 1;
                $item = $list[$index];
                $key = UrlNormalizer::key($item['url']);
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $merged[] = [
                    'title' => $item['title'],
                    'url' => $item['url'],
                    'snippet' => $item['snippet'],
                    'engine' => $engineId,
                ];

                if (count($merged) >= $maxResults) {
                    break;
                }
            }

            if (!$progress) {
                break;
            }
        }

        return $merged;
    }

    protected function applySiteFilter(string $query, string $host): string
    {
        if ($host === '') {
            return $query;
        }

        if (preg_match('/\bsite:\s*' . preg_quote($host, '/') . '(?![\w.-])/i', $query) === 1) {
            return $query;
        }

        return $query . ' site:' . $host;
    }

    /** The callback can flip while a fetch is in progress, so it is sampled again. */
    protected function aborted(callable $shouldAbort): bool
    {
        return $shouldAbort() === true;
    }
}
