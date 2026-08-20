#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Smoke-fetch public HTML pages through LlmHtml (not WebFetchTool).
 *
 * Usage:
 *   php packages/llm-html/bin/smoke-fetch.php
 *   php packages/llm-html/bin/smoke-fetch.php --diverse
 *   php packages/llm-html/bin/smoke-fetch.php --cn
 *   php packages/llm-html/bin/smoke-fetch.php https://a.example/ https://b.example/
 */

use Switon\Core\InjectorInterface;
use Switon\Di\Container;
use Switon\Di\Injector;
use Switon\LlmHtml\LlmHtmlInterface;

$repoRoot = dirname(__DIR__, 3);
require $repoRoot . '/vendor/autoload.php';
require $repoRoot . '/tests/bootstrap.php';

$defaultUrls = [
    'https://example.com/',
    'https://en.wikipedia.org/wiki/Markdown',
    'https://docs.python.org/3/tutorial/introduction.html',
    'https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/Overview',
    'https://info.cern.ch/',
    'https://www.rfc-editor.org/rfc/rfc8259',
    'https://blog.golang.org/go1.22',
];

// Cross-industry set (bot-reachable from this network):
// health / intl-org / finance / edu / science / retail / food.
$diverseUrls = [
    'https://www.nhs.uk/conditions/common-cold/',
    'https://www.un.org/en/about-us',
    'https://www.ecb.europa.eu/home/html/index.en.html',
    'https://www.mit.edu/',
    'https://arxiv.org/abs/1706.03762',
    'https://www.ikea.com/us/en/',
    'https://tasty.co/recipe/the-best-fudgy-brownies',
];

// Domestic CN set (prefer article/list pages over SPA portals):
// 政务 / 新闻 / 财经 / 教育 / 科技媒体 / 文娱 / 美食.
$cnUrls = [
    'https://www.gov.cn/yaowen/liebiao/202409/content_6974344.htm',
    'https://www.guancha.cn/internation',
    'https://www.yicai.com/news/',
    'https://www.fudan.edu.cn/',
    'https://www.ruanyifeng.com/blog/2024/02/weekly-issue-290.html',
    'https://book.douban.com/subject/1084336/',
    'https://www.xiachufang.com/recipe/100000000/',
];

$argvUrls = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $arg): bool => !str_starts_with($arg, '-'),
));
$urls = match (true) {
    in_array('--diverse', $argv, true) => $diverseUrls,
    in_array('--cn', $argv, true) => $cnUrls,
    $argvUrls !== [] => $argvUrls,
    default => $defaultUrls,
};

$outDir = $repoRoot . '/runtime/specflow/llm-html/smoke-' . date('Ymd-His');
if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");
    exit(1);
}

$container = new Container();
$container->set(InjectorInterface::class, new Injector($container));
$llmHtml = $container->get(LlmHtmlInterface::class);

echo "Out: {$outDir}\n\n";

$summary = [];
foreach ($urls as $i => $url) {
    $n = $i + 1;
    echo str_repeat('=', 72) . "\n";
    echo "[{$n}/" . count($urls) . "] {$url}\n";

    $fetched = httpGet($url);
    if ($fetched['error'] !== null) {
        echo "FAIL fetch: {$fetched['error']}\n\n";
        $summary[] = ['url' => $url, 'ok' => false, 'error' => $fetched['error']];
        continue;
    }

    $html = $fetched['body'];
    $result = $llmHtml->convert($html);
    $preview = preview($result->content, 500);

    $row = [
        'url' => $url,
        'ok' => true,
        'http' => $fetched['status'],
        'html_bytes' => strlen($html),
        'title' => $result->title,
        'strategy' => $result->strategy,
        'cleanup' => $result->cleanup->value,
        'usedMain' => $result->usedMainContent,
        'md_chars' => mb_strlen($result->content),
        'md_lines' => substr_count($result->content, "\n") + 1,
    ];
    $summary[] = $row;

    echo "HTTP {$row['http']}  html={$row['html_bytes']}B  md={$row['md_chars']} chars / {$row['md_lines']} lines\n";
    echo "title: " . ($row['title'] !== '' ? $row['title'] : '(empty)') . "\n";
    echo "strategy={$row['strategy']}  cleanup={$row['cleanup']}  usedMain=" . ($row['usedMain'] ? 'true' : 'false') . "\n";
    echo "--- preview ---\n{$preview}\n\n";

    $slug = sprintf('%02d-%s', $n, slugUrl($url));
    file_put_contents($outDir . "/{$slug}.html", $html);
    file_put_contents($outDir . "/{$slug}.md", $result->content);
    file_put_contents($outDir . "/{$slug}.json", json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}

file_put_contents($outDir . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

echo str_repeat('=', 72) . "\n";
echo "Done. summary.json written.\n";

/**
 * @return array{status: int, body: string, error: ?string}
 */
function httpGet(string $url): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['status' => 0, 'body' => '', 'error' => 'curl_init failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SwitonLlmHtmlSmoke/1.0; +research)',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9,en-US;q=0.8,en;q=0.7',
        ],
        CURLOPT_ENCODING => '',
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['status' => $status, 'body' => '', 'error' => $err !== '' ? $err : 'empty body'];
    }

    if ($status >= 400 || $status === 0) {
        return ['status' => $status, 'body' => (string) $body, 'error' => "HTTP {$status}"];
    }

    return ['status' => $status, 'body' => (string) $body, 'error' => null];
}

function preview(string $text, int $max): string
{
    $text = trim($text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }

    return mb_substr($text, 0, $max) . "\n…";
}

function slugUrl(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST) ?: 'page';
    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
    $slug = $host . ($path !== '' ? '-' . str_replace('/', '-', $path) : '');

    return preg_replace('/[^a-zA-Z0-9._-]+/', '-', $slug) ?: 'page';
}
