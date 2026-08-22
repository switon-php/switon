<?php

declare(strict_types=1);

namespace Switon\Search\Tests\Fixtures;

use Switon\Search\Site;
use Switon\Search\SiteSearchInterface;

/** Stub {@see SiteSearchInterface} for unit/live tool tests. */
class StubSiteSearch implements SiteSearchInterface
{
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

    public function search(Site $site, string $query, int $maxResults, callable $shouldAbort, int $page = 1): array
    {
        $this->lastSite = $site->id;
        $this->lastQuery = $query;
        $this->lastMaxResults = $maxResults;
        if ($this->status !== null) {
            return ['results' => [], 'status' => $this->status];
        }

        return array_slice($this->results, 0, $maxResults);
    }
}
