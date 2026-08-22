<?php

declare(strict_types=1);

/**
 * Fetch every site file through the headed Chrome profile and print cleaned records.
 *
 *   php packages/search/tests/manual/site-live.php [keyword] [site]
 *
 * Profile: ~/.switon/chrome/headed (port 59223). Login there is what the page sees.
 */

use Switon\Browser\HeadedBrowserInterface;
use Switon\Core\App;
use Switon\Search\SiteExtractorInterface;
use Switon\Search\Sites;
use Switon\Testing\Container;

$repoRoot = dirname(__DIR__, 4);
require $repoRoot . '/vendor/autoload.php';
require $repoRoot . '/tests/bootstrap.php';

$container = new Container();
App::setContainer($container);

$keyword = $argv[1] ?? 'php';
$only = $argv[2] ?? '';
$extractor = $container->make(SiteExtractorInterface::class);
$browser = $container->make(HeadedBrowserInterface::class);
$registry = $container->make(Sites::class);
$registry->reload();
$sites = $registry->all();

foreach ($sites as $id => $site) {
    if ($only !== '' && $id !== $only) {
        continue;
    }
    $url = strtr($site->request, ['{keyword}' => rawurlencode($keyword), '{offset}' => '0', '{limit}' => (string) max(1, $site->size), '{page}' => '1']);
    echo "== {$id}\n{$url}\n";
    try {
        $html = $browser->fetchHtml($url, static fn (): bool => false, $site->ready !== '' ? $site->ready : null);
        $body = $site->kind === 'json' ? jsonText($html) : $html;
        $hits = $extractor->extract($site, $body, $url);
    } catch (Throwable $e) {
        echo $e->getMessage() . "\n\n";
        continue;
    }

    echo count($hits) . " records\n";
    foreach (array_slice($hits, 0, 3) as $index => $hit) {
        echo '  ' . ($index + 1) . '.';
        foreach ($hit as $field => $value) {
            $shown = preg_replace('/\s+/u', ' ', $value) ?? $value;
            if (mb_strlen($shown) > 90) {
                $shown = mb_substr($shown, 0, 90) . '…';
            }
            echo " {$field}=" . ($shown === '' ? '""' : $shown);
        }
        echo "\n";
    }
    echo "\n";
}

/** Chrome shows a JSON document inside `<pre>`. */
function jsonText(string $html): string
{
    $trimmed = ltrim($html);
    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
        return $trimmed;
    }

    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    foreach ($dom->getElementsByTagName('pre') as $pre) {
        $text = trim($pre->textContent);
        if ($text !== '' && ($text[0] === '{' || $text[0] === '[')) {
            return $text;
        }
    }

    return $html;
}
