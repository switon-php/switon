<?php

declare(strict_types=1);

/**
 * Live check of every site through the real {@see \Switon\Search\SiteSearch} path.
 *
 *   php packages/search/tests/manual/site-live-all.php [keyword] [siteId]
 *
 * Uses the configured fetcher: curl for plain sites, the CDP browser for `fetch: browser` / `ready`.
 */

use Switon\Core\App;
use Switon\Search\Sites;
use Switon\Search\SiteSearchInterface;
use Switon\Testing\Container;

$repoRoot = dirname(__DIR__, 4);
require $repoRoot . '/vendor/autoload.php';
require $repoRoot . '/tests/bootstrap.php';

$container = new Container();
App::setContainer($container);

$keyword = $argv[1] ?? 'php';
$only = $argv[2] ?? '';

$search = $container->make(SiteSearchInterface::class);
$registry = $container->make(Sites::class);
$registry->reload();

$tally = [];
foreach ($registry->all() as $id => $site) {
    if ($only !== '' && $id !== $only) {
        continue;
    }
    $started = microtime(true);
    try {
        $returned = $search->search($site, $keyword, 5, static fn (): bool => false, 1);
    } catch (Throwable $e) {
        printf("%-16s THROW   %s\n", $id, substr($e->getMessage(), 0, 90));
        $tally['throw'] = ($tally['throw'] ?? 0) + 1;
        continue;
    }
    $ms = (int) ((microtime(true) - $started) * 1000);

    if (isset($returned['results'], $returned['status'])) {
        printf("%-16s HTTP %-4d %5dms\n", $id, $returned['status'], $ms);
        $tally['http'] = ($tally['http'] ?? 0) + 1;
        continue;
    }

    $hits = $returned;
    $n = count($hits);
    $tally[$n > 0 ? 'ok' : 'empty'] = ($tally[$n > 0 ? 'ok' : 'empty'] ?? 0) + 1;
    printf("%-16s %2d records %5dms\n", $id, $n, $ms);
    foreach (array_slice($hits, 0, 2) as $hit) {
        printf("   %-45s %s\n", mb_substr($hit['title'] ?? '', 0, 43), mb_substr($hit['url'] ?? '', 0, 60));
        $snip = preg_replace('/\s+/u', ' ', $hit['snippet'] ?? '') ?? '';
        if ($snip !== '') {
            printf("   %-45s\n", mb_substr($snip, 0, 43));
        }
    }
}

echo "\n", json_encode($tally), "\n";
