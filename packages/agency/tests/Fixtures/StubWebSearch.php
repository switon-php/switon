<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Fixtures;

use Switon\Search\WebSearchInterface;

use function array_slice;
use function preg_match;
use function preg_quote;

/** Stub {@see WebSearchInterface} for live tool tests (tool choice is under test, not live HTML). */
class StubWebSearch implements WebSearchInterface
{
    /** @var list<array{title: string, url: string, snippet: string, engine?: string}> */
    public array $results = [
        [
            'title' => 'Example Page',
            'url' => 'https://example.com/docs',
            'snippet' => 'Example snippet.',
            'engine' => 'stub',
        ],
    ];

    public string $lastQuery = '';
    public ?string $lastLang = null;
    public ?string $lastSite = null;
    public int $lastMaxResults = 0;

    public function search(
        string $query,
        int $maxResults,
        callable $shouldAbort,
        ?string $lang = null,
        ?string $site = null,
    ): array {
        $effective = $query;
        if ($site !== null && $site !== '') {
            if (preg_match('/\bsite:\s*' . preg_quote($site, '/') . '\b/i', $query) !== 1) {
                $effective = $query . ' site:' . $site;
            }
        }

        $this->lastQuery = $effective;
        $this->lastLang = $lang;
        $this->lastSite = $site;
        $this->lastMaxResults = $maxResults;

        $slice = array_slice($this->results, 0, $maxResults);
        foreach ($slice as $i => $row) {
            if (!isset($row['engine'])) {
                $slice[$i]['engine'] = 'stub';
            }
        }

        return $slice;
    }
}
