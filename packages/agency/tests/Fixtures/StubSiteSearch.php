<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Fixtures;

use Switon\Search\SiteSearchInterface;

/** Stub {@see SiteSearchInterface} for live tool tests (tool choice is under test, not remote APIs). */
class StubSiteSearch implements SiteSearchInterface
{
    /** @var list<string> */
    public array $siteIds = [
        'github',
        'gitlab',
        'wikipedia',
        'baike',
        'packagist',
        'npm',
        'pypi',
        'maven',
        'pkggo',
        'stackoverflow',
        'mdn',
    ];

    /** @var list<array{title: string, url: string, snippet: string}> */
    public array $results = [
        [
            'title' => 'example/pkg',
            'url' => 'https://example.com/pkg',
            'snippet' => 'Example package.',
        ],
    ];

    public string $lastSite = '';
    public string $lastQuery = '';
    public int $lastMaxResults = 0;
    public ?int $status = null;

    public function sites(): array
    {
        return $this->siteIds;
    }

    public function search(string $site, string $query, int $maxResults, callable $shouldAbort, int $page = 1): array
    {
        $this->lastSite = $site;
        $this->lastQuery = $query;
        $this->lastMaxResults = $maxResults;
        if ($this->status !== null) {
            return ['results' => [], 'status' => $this->status];
        }

        return array_slice($this->results, 0, $maxResults);
    }
}
