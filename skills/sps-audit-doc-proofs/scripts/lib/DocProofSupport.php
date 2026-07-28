<?php

declare(strict_types=1);

namespace Switon\Skills\DocProofs;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class DocProofSupport
{
    public static function repoRoot(): string
    {
        return realpath(__DIR__ . '/../../../../') ?: getcwd();
    }

    public static function logicalTargets(string $root): array
    {
        return [
            'tutorial' => [[
                'label' => 'tutorial',
                'path' => $root . '/docs/en/tutorial',
            ]],
            'guides' => [[
                'label' => 'guides',
                'path' => $root . '/docs/en/guides',
            ]],
            'getting-started' => [[
                'label' => 'getting-started',
                'path' => $root . '/docs/en/getting-started',
            ]],
            'learning-mainline' => [
                [
                    'label' => 'getting-started',
                    'path' => $root . '/docs/en/getting-started',
                ],
                [
                    'label' => 'guides',
                    'path' => $root . '/docs/en/guides',
                ],
                [
                    'label' => 'tutorial',
                    'path' => $root . '/docs/en/tutorial',
                ],
            ],
            'learning-docs' => [[
                'label' => 'learning-docs',
                'path' => $root . '/docs/en',
            ]],
        ];
    }

    public static function parseArgs(array $argv): array
    {
        $options = [
            'json' => false,
            'fail-on-issues' => false,
        ];
        $targets = [];

        foreach ($argv as $index => $arg) {
            if ($index === 0) {
                continue;
            }

            if ($arg === '--json') {
                $options['json'] = true;
                continue;
            }

            if ($arg === '--fail-on-issues') {
                $options['fail-on-issues'] = true;
                continue;
            }

            if (str_starts_with($arg, '--')) {
                throw new RuntimeException('Unknown option: ' . $arg);
            }

            $targets[] = $arg;
        }

        return [$options, $targets];
    }

    public static function resolveTargets(string $root, array $args): array
    {
        $logicalTargets = self::logicalTargets($root);

        if ($args === []) {
            $args = ['learning-docs'];
        }

        $targets = [];
        foreach ($args as $arg) {
            if (isset($logicalTargets[$arg])) {
                foreach ($logicalTargets[$arg] as $entry) {
                    $path = $entry['path'];
                    if (!isset($targets[$path])) {
                        $targets[$path] = [
                            'label' => $entry['label'],
                            'path' => $path,
                            'labels' => [$entry['label']],
                        ];
                        continue;
                    }

                    if (!in_array($entry['label'], $targets[$path]['labels'], true)) {
                        $targets[$path]['labels'][] = $entry['label'];
                    }
                }
                continue;
            }

            $candidate = str_starts_with($arg, '/') ? $arg : $root . '/' . ltrim($arg, '/');
            $real = realpath($candidate);
            if ($real === false) {
                throw new RuntimeException('Target not found: ' . $arg);
            }

            $targets[$real] = [
                'label' => self::relativePath($root, $real),
                'path' => $real,
                'labels' => [self::relativePath($root, $real)],
            ];
        }

        return array_values($targets);
    }

    public static function collectMarkdownFiles(array $targets): array
    {
        $files = [];
        foreach ($targets as $target) {
            foreach (self::collectTargetFiles($target['path']) as $file) {
                $files[$file] = true;
            }
        }

        $files = array_keys($files);
        sort($files);

        return $files;
    }

    public static function extractAssertions(string $root, array $files): array
    {
        $summary = [
            'php_snippet' => 0,
            'command' => 0,
            'symbol' => 0,
            'link' => 0,
            'http_path' => 0,
        ];
        $byFile = [];
        $samples = [
            'commands' => [],
            'symbols' => [],
            'links' => [],
            'http_paths' => [],
        ];

        foreach ($files as $file) {
            $markdown = (string) file_get_contents($file);
            $counts = [
                'php_snippet' => self::countFencedBlocks($markdown, 'php'),
                'command' => 0,
                'symbol' => 0,
                'link' => 0,
                'http_path' => 0,
            ];

            $commands = self::extractCommands($markdown);
            $symbols = self::extractSymbols($markdown);
            $links = self::extractLinks($markdown);
            $httpPaths = self::extractHttpPaths($markdown);

            $counts['command'] = count($commands);
            $counts['symbol'] = count($symbols);
            $counts['link'] = count($links);
            $counts['http_path'] = count($httpPaths);

            foreach ($summary as $type => $count) {
                $summary[$type] += $counts[$type];
            }

            $byFile[] = [
                'file' => self::relativePath($root, $file),
                'counts' => $counts,
            ];

            $samples['commands'] = self::mergeSamples($samples['commands'], $commands);
            $samples['symbols'] = self::mergeSamples($samples['symbols'], $symbols);
            $samples['links'] = self::mergeSamples($samples['links'], $links);
            $samples['http_paths'] = self::mergeSamples($samples['http_paths'], $httpPaths);
        }

        return [
            'files_scanned' => count($files),
            'assertion_counts' => $summary,
            'by_file' => $byFile,
            'samples' => $samples,
        ];
    }

    public static function determineRoutes(array $targets, array $assertions): array
    {
        $routes = [];
        $counts = $assertions['assertion_counts'];
        $targetPaths = array_column($targets, 'path');

        if ($counts['php_snippet'] > 0) {
            $routes[] = [
                'route' => 'snippet_validation',
                'reason' => 'Found fenced PHP snippets that should be checked with the shared validator.',
            ];
        }

        if ($counts['link'] > 0) {
            $routes[] = [
                'route' => 'link_audit',
                'reason' => 'Found Markdown links that need anchor and path integrity checks.',
            ];
        }

        if ($counts['symbol'] > 0 || $counts['command'] > 0) {
            $routes[] = [
                'route' => 'identifier_review',
                'reason' => 'Found machine-meaningful symbols or commands that still need source-backed review.',
            ];
        }

        foreach ($targetPaths as $targetPath) {
            if (
                str_contains($targetPath, '/docs/en/tutorial')
                || str_contains($targetPath, '/docs/en/guides')
                || str_contains($targetPath, '/docs/en/getting-started')
            ) {
                $routes[] = [
                    'route' => 'learning_mainline_runtime',
                    'reason' => 'This scope touches the shared beginner path, so run the controlled learning-mainline proof app for baseline HTTP and CLI coverage.',
                ];
                break;
            }
        }

        return $routes;
    }

    public static function determineLinkAuditTree(string $root, array $targets): string
    {
        $labels = array_column($targets, 'label');
        foreach ($labels as $label) {
            if (!str_starts_with($label, 'docs/en/')) {
                return $root . '/docs/en';
            }
        }

        return $root . '/docs/en';
    }

    public static function runCommand(string $command, string $cwd): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $environment = $_ENV;
        $environment['PATH'] = getenv('PATH') ?: ($environment['PATH'] ?? '');
        $environment['HOME'] = getenv('HOME') ?: ($environment['HOME'] ?? '');
        $environment['SHELL'] = getenv('SHELL') ?: ($environment['SHELL'] ?? '');
        $environment['XDEBUG_MODE'] = 'off';

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start command: ' . $command);
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'command' => $command,
            'cwd' => $cwd,
            'exit_code' => $exitCode,
            'stdout' => trim((string) $stdout),
            'stderr' => trim((string) $stderr),
        ];
    }

    public static function printPayload(array $payload, bool $json): void
    {
        if ($json) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_scalar($value) || $value === null) {
                echo $key . ': ' . (string) $value . "\n";
            }
        }
    }

    protected static function collectTargetFiles(string $target): array
    {
        if (is_file($target)) {
            return str_ends_with($target, '.md') ? [$target] : [];
        }

        if (!is_dir($target)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if (str_ends_with($path, '.md')) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    protected static function countFencedBlocks(string $markdown, string $info): int
    {
        if (!preg_match_all('/```' . preg_quote($info, '/') . '\s*\n(.*?)\n```/s', $markdown, $matches)) {
            return 0;
        }

        return count($matches[1]);
    }

    protected static function extractCommands(string $markdown): array
    {
        $commands = [];

        if (preg_match_all('/```(?:bash|sh|shell)\s*\n(.*?)\n```/s', $markdown, $matches)) {
            foreach ($matches[1] as $block) {
                foreach (preg_split('/\R/', trim($block)) ?: [] as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $commands[] = $line;
                    }
                }
            }
        }

        if (preg_match_all('/`([^`\n]*(?:bash\s+bin\/console|SERVER_TYPE=php|php\s+public\/index\.php)[^`\n]*)`/', $markdown, $matches)) {
            foreach ($matches[1] as $command) {
                $commands[] = trim($command);
            }
        }

        return array_values(array_unique($commands));
    }

    protected static function extractSymbols(string $markdown): array
    {
        $symbols = [];

        if (preg_match_all('/`([^`\n]+)`/', $markdown, $matches)) {
            foreach ($matches[1] as $token) {
                $token = trim($token);
                if (
                    preg_match('/^[A-Z][A-Za-z0-9_\\\\]+::[A-Za-z_][A-Za-z0-9_]*\(\)$/', $token) === 1
                    || preg_match('/^#\[[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\]$/', $token) === 1
                    || preg_match('/^[A-Z][A-Za-z0-9_\\\\]*\\\\[A-Z][A-Za-z0-9_\\\\]+$/', $token) === 1
                    || preg_match('/^[A-Z][A-Za-z0-9_]*(?:Interface|Controller|Mapping|Command|Dispatcher|Validator|Kernel|Service|Input|Repository|Entity)$/', $token) === 1
                ) {
                    $symbols[] = $token;
                }
            }
        }

        return array_values(array_unique($symbols));
    }

    protected static function extractLinks(string $markdown): array
    {
        if (!preg_match_all('/\[[^\]]+\]\(([^)]+)\)/', $markdown, $matches)) {
            return [];
        }

        return array_values(array_unique(array_map('trim', $matches[1])));
    }

    protected static function extractHttpPaths(string $markdown): array
    {
        $paths = [];

        if (preg_match_all('/`(\/[A-Za-z0-9_\/\-\{\}]+)`/', $markdown, $matches)) {
            foreach ($matches[1] as $path) {
                $paths[] = trim($path);
            }
        }

        return array_values(array_unique($paths));
    }

    protected static function mergeSamples(array $current, array $new): array
    {
        $merged = array_values(array_unique(array_merge($current, $new)));

        return array_slice($merged, 0, 20);
    }

    protected static function relativePath(string $root, string $path): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedPath = str_replace('\\', '/', $path);

        if (str_starts_with($normalizedPath, $normalizedRoot . '/')) {
            return substr($normalizedPath, strlen($normalizedRoot) + 1);
        }

        return $normalizedPath;
    }
}
