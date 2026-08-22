<?php

declare(strict_types=1);

namespace Switon\Search\Web\Engine;

use JsonException;
use Switon\Core\Attribute\Autowired;
use Switon\Search\Web\EngineInterface;
use Switon\Search\Web\PageFetchHintsInterface;
use Switon\Search\Web\UrlNormalizer;

use function count;
use function html_entity_decode;
use function is_array;
use function json_decode;
use function max;
use function min;
use function parse_url;
use function rawurlencode;
use function rtrim;
use function str_starts_with;
use function strip_tags;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;
use const JSON_THROW_ON_ERROR;

/** Zhihu `/api/v4/search_v3`. Keeps `question`, `answer`, and `article`. */
class ZhihuEngine implements EngineInterface, PageFetchHintsInterface
{
    #[Autowired] protected string $apiBase = 'https://www.zhihu.com';
    #[Autowired] protected int $pageSize = 20;

    public function supportsSiteQuery(): bool
    {
        return false;
    }

    public function pageSize(): int
    {
        return max(1, min($this->pageSize, 20));
    }

    public function originUrl(): ?string
    {
        return rtrim($this->apiBase, '/') . '/';
    }

    public function refererUrl(string $query, int $maxResults, ?string $lang): ?string
    {
        return rtrim($this->apiBase, '/') . '/search?type=content&q=' . rawurlencode($query);
    }

    public function buildUrl(string $query, int $maxResults, ?string $lang): string
    {
        $limit = max(1, min($maxResults, 20));

        return rtrim($this->apiBase, '/') . '/api/v4/search_v3'
            . '?gk_version=gz-gaokao&t=general&q=' . rawurlencode($query)
            . '&correction=1&offset=0&limit=' . $limit
            . '&filter_fields=&lc_idx=0&show_all_topics=0&search_source=Normal';
    }

    public function parse(string $body, int $maxResults): array
    {
        try {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (!is_array($json) || !is_array($json['data'] ?? null)) {
            return [];
        }

        $results = [];
        foreach ($json['data'] as $row) {
            if (count($results) >= $maxResults) {
                break;
            }
            if (!is_array($row)) {
                continue;
            }

            $object = is_array($row['object'] ?? null) ? $row['object'] : $row;
            $parsed = $this->mapObject($object, $row);
            if ($parsed === null) {
                continue;
            }
            $results[] = $parsed;
        }

        return $results;
    }

    /**
     * One readable row, or null for a profile, topic, collection, or video.
     *
     * @param array<string, mixed> $object
     * @param array<string, mixed> $row
     *
     * @return array{title: string, url: string, snippet: string}|null
     */
    protected function mapObject(array $object, array $row): ?array
    {
        $type = (string) ($object['type'] ?? '');
        $title = '';
        $url = '';
        $snippet = '';

        if ($type === 'article') {
            $title = $this->plain((string) ($object['title'] ?? ''));
            $id = (string) ($object['id'] ?? '');
            $url = $id !== '' ? 'https://zhuanlan.zhihu.com/p/' . $id : $this->publicUrl((string) ($object['url'] ?? ''));
            $snippet = $this->plain((string) ($object['excerpt'] ?? $object['excerpt_new'] ?? ''));
        } elseif ($type === 'answer') {
            $question = is_array($object['question'] ?? null) ? $object['question'] : [];
            $title = $this->plain((string) ($question['title'] ?? $object['title'] ?? ''));
            $qid = (string) ($question['id'] ?? '');
            $aid = (string) ($object['id'] ?? '');
            if ($qid !== '' && $aid !== '') {
                $url = 'https://www.zhihu.com/question/' . $qid . '/answer/' . $aid;
            } else {
                $url = $this->publicUrl((string) ($object['url'] ?? ''));
            }
            $snippet = $this->plain((string) ($object['excerpt'] ?? $object['excerpt_new'] ?? ''));
        } elseif ($type === 'question') {
            $title = $this->plain((string) ($object['title'] ?? $object['name'] ?? ''));
            $id = (string) ($object['id'] ?? '');
            $url = $id !== '' ? 'https://www.zhihu.com/question/' . $id : $this->publicUrl((string) ($object['url'] ?? ''));
            $snippet = $this->plain((string) ($object['excerpt'] ?? $object['excerpt_new'] ?? ''));
        } else {
            return null;
        }

        if ($snippet === '' && is_array($row['highlight'] ?? null)) {
            $snippet = $this->plain((string) ($row['highlight']['description'] ?? $row['highlight']['title'] ?? ''));
        }

        if ($title === '' || $url === '') {
            return null;
        }

        return [
            'title' => $title,
            'url' => $url,
            'snippet' => UrlNormalizer::collapseWhitespace($snippet),
        ];
    }

    protected function publicUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, 'https://api.zhihu.com/answers/')) {
            return 'https://www.zhihu.com/answer/' . substr($url, strlen('https://api.zhihu.com/answers/'));
        }
        if (str_starts_with($url, 'https://api.zhihu.com/articles/')) {
            return 'https://zhuanlan.zhihu.com/p/' . substr($url, strlen('https://api.zhihu.com/articles/'));
        }
        if (str_starts_with($url, 'https://api.zhihu.com/questions/')) {
            return 'https://www.zhihu.com/question/' . substr($url, strlen('https://api.zhihu.com/questions/'));
        }
        if (strtolower((string) (parse_url($url, PHP_URL_HOST) ?? '')) === 'api.zhihu.com') {
            return '';
        }

        return $url;
    }

    protected function plain(string $text): string
    {
        return UrlNormalizer::collapseWhitespace(
            html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }
}
