#!/usr/bin/env php
<?php

/**
 * CLI smoke test for {@see \Switon\Search\WebFetch} (the tool wrapper is for models).
 *
 * Usage:
 *   php packages/search/bin/web-fetch.php <url> [--json] [--meta] [--raw] [--timeout=3] [--ceiling=32]
 */

use Switon\Core\App;
use Switon\Search\WebFetch;
use Switon\Search\WebFetchInterface;
use Switon\Testing\Container;

$repoRoot = dirname(__DIR__, 3);
require $repoRoot . '/vendor/autoload.php';
require $repoRoot . '/tests/bootstrap.php';

/** @return array{url: string, json: bool, meta: bool, raw: bool, timeout: int, ceiling: int} */
function parseArgs(array $argv): array
{
    $url = '';
    $json = false;
    $meta = false;
    $raw = false;
    $timeout = 3;
    $ceiling = 32;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            fwrite(STDOUT, <<<HELP
Usage: php packages/search/bin/web-fetch.php <url> [options]

Options:
  --json                      Output full tool result as JSON
  --meta                      Print metadata only (no content body)
  --raw                       Return raw response body (no HTML-to-markdown)
  --timeout=SECONDS           Request timeout (default: 3)
  --ceiling=MIB               Download cap in MiB (default: 32)
  -h, --help                  Show this help

Examples:
  php packages/search/bin/web-fetch.php https://www.php.net/manual/en/function.array-map.php
  php packages/search/bin/web-fetch.php --meta https://www.php.net/manual/en/

HELP);
            exit(0);
        }

        if ($arg === '--json') {
            $json = true;
            continue;
        }

        if ($arg === '--meta') {
            $meta = true;
            continue;
        }

        if ($arg === '--raw') {
            $raw = true;
            continue;
        }

        if (str_starts_with($arg, '--timeout=')) {
            $timeout = max(1, (int) substr($arg, 10));
            continue;
        }

        if (str_starts_with($arg, '--ceiling=')) {
            $ceiling = max(1, (int) substr($arg, 10));
            continue;
        }

        if (str_starts_with($arg, '-')) {
            fwrite(STDERR, "Unknown option: {$arg}\n");
            exit(2);
        }

        if ($url === '') {
            $url = $arg;
        }
    }

    if ($url === '') {
        fwrite(STDERR, "Missing URL. Run with --help for usage.\n");
        exit(2);
    }

    return compact('url', 'json', 'meta', 'raw', 'timeout', 'ceiling');
}

$options = parseArgs($argv);

$container = new Container();
App::setContainer($container);

$container->set(WebFetchInterface::class, $container->make(WebFetch::class, [
    'timeout' => $options['timeout'],
    'ceiling' => $options['ceiling'],
]));

/** @var WebFetchInterface $fetcher */
$fetcher = $container->get(WebFetchInterface::class);

try {
    // Call the fetcher directly: this script wants the body, not the tool envelope, so there is
    // no agent to hand a tool. The tool wrapper is for models, not for a CLI.
    /** @var array<string, mixed> $result */
    $result = $fetcher->fetch($options['url'], static fn (): bool => false, $options['raw']);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if ($options['json']) {
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    $json = json_encode($result, $flags);
    if ($json === false) {
        fwrite(STDERR, 'Failed to encode JSON: ' . json_last_error_msg() . "\n");
        exit(1);
    }

    fwrite(STDOUT, $json . "\n");
    exit(0);
}

$lines = [
    'url:          ' . ($result['url'] ?? $options['url']),
    'status_code:  ' . ($result['status_code'] ?? ''),
    'content_type: ' . ($result['content_type'] ?? ''),
    'title:        ' . ($result['title'] ?? ''),
    'format:       ' . ($result['format'] ?? 'markdown'),
];

fwrite(STDOUT, implode("\n", $lines) . "\n");

if (!$options['meta']) {
    fwrite(STDOUT, "\n--- content ---\n\n");
    fwrite(STDOUT, (string) ($result['content'] ?? ''));
    if (!str_ends_with((string) ($result['content'] ?? ''), "\n")) {
        fwrite(STDOUT, "\n");
    }
}

exit(0);
