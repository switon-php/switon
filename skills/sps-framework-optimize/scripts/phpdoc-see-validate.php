#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Validate PHPDoc @see targets (class + optional ::method()) inside packages/<package>/src.
 *
 * Checks:
 * - illegal targets (wildcard *, trailing backslash)
 * - missing class targets (in-repo)
 * - missing method targets (in-repo, naive function name scan)
 *
 * Defaults to validating Switon\* only; pass --all to validate any \FQCN.
 *
 * Usage:
 *   php phpdoc-see-validate.php [--json] [--fail-on-issues] [--all] [--root=/abs/path]
 */

final class PhpdocSeeValidate
{
    public static function main(array $argv): int
    {
        $opt = self::parseArgs($argv);

        $root = $opt['root'] ?? self::repoRoot();
        $srcRoot = rtrim($root, '/') . '/packages';

        if (!is_dir($srcRoot)) {
            fwrite(STDERR, "Invalid root (packages/ not found): {$root}\n");
            return 2;
        }

        $index = self::indexClasses($srcRoot);
        $issues = self::scanSeeTargets($srcRoot, $index, (bool)($opt['all'] ?? false));

        $summary = [
            'root' => $root,
            'scanned' => [
                'packages_src_php_files' => $issues['stats']['files_scanned'],
                'see_entries' => $issues['stats']['see_entries'],
            ],
            'issues' => [
                'total' => count($issues['items']),
                'illegal_targets' => $issues['stats']['illegal_targets'],
                'missing_class' => $issues['stats']['missing_class'],
                'missing_method' => $issues['stats']['missing_method'],
            ],
        ];

        if (!empty($opt['json'])) {
            echo json_encode(['summary' => $summary, 'items' => $issues['items']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        } else {
            echo "invalid_see_targets " . count($issues['items']) . "\n";
            foreach ($issues['items'] as $it) {
                echo $it['kind'] . " " . $it['file'] . " => " . $it['target'] . "\n";
            }
        }

        if (!empty($opt['fail-on-issues']) && count($issues['items']) > 0) {
            return 1;
        }
        return 0;
    }

    /** @return array{json?:bool,fail-on-issues?:bool,all?:bool,root?:string} */
    protected static function parseArgs(array $argv): array
    {
        $opt = [];
        foreach ($argv as $i => $arg) {
            if ($i === 0) {
                continue;
            }
            if ($arg === '--json') {
                $opt['json'] = true;
            } elseif ($arg === '--fail-on-issues') {
                $opt['fail-on-issues'] = true;
            } elseif ($arg === '--all') {
                $opt['all'] = true;
            } elseif (str_starts_with($arg, '--root=')) {
                $opt['root'] = substr($arg, strlen('--root='));
            } else {
                fwrite(STDERR, "Unknown arg: {$arg}\n");
                exit(2);
            }
        }
        return $opt;
    }

    protected static function repoRoot(): string
    {
        // canonical location: skills/sps-framework-optimize/scripts/
        return realpath(__DIR__ . '/../../../../..') ?: getcwd();
    }

    /** @return array<string, string> fqcn => absolute file path */
    protected static function indexClasses(string $packagesRoot): array
    {
        $index = [];
        foreach (self::iterPhpFiles($packagesRoot) as $file) {
            // only index package sources
            if (!str_contains($file, '/src/')) {
                continue;
            }
            $fqcn = self::extractFqcn($file);
            if ($fqcn !== null && !isset($index[$fqcn])) {
                $index[$fqcn] = $file;
            }
        }
        return $index;
    }

    /**
     * @param array<string, string> $index
     * @return array{items:list<array{kind:string,file:string,target:string,target_file?:string}>,stats:array<string,int>}
     */
    protected static function scanSeeTargets(string $packagesRoot, array $index, bool $all): array
    {
        $items = [];
        $stats = [
            'files_scanned' => 0,
            'see_entries' => 0,
            'illegal_targets' => 0,
            'missing_class' => 0,
            'missing_method' => 0,
        ];

        $seen = [];
        foreach (self::iterPhpFiles($packagesRoot) as $file) {
            if (!str_contains($file, '/src/')) {
                continue;
            }
            $stats['files_scanned']++;
            $code = (string)file_get_contents($file);

            foreach (self::extractSeeTargets($code) as $t) {
                $stats['see_entries']++;

                $target = $t['class'] . ($t['method'] !== null ? ('::' . $t['method'] . '()') : '');

                // ignore external deps unless --all
                if (!$all) {
                    if (!str_starts_with($t['class'], 'Switon\\')) {
                        continue;
                    }
                }

                if (str_contains($t['class'], '*')) {
                    $stats['illegal_targets']++;
                    $key = "{$file}|{$target}|wildcard";
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $items[] = ['kind' => 'wildcard', 'file' => $file, 'target' => $target];
                    }
                    continue;
                }
                if (str_ends_with($t['class'], '\\')) {
                    $stats['illegal_targets']++;
                    $key = "{$file}|{$target}|trailing_backslash";
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $items[] = ['kind' => 'trailing_backslash', 'file' => $file, 'target' => $target];
                    }
                    continue;
                }

                $targetFile = $index[$t['class']] ?? null;
                if ($targetFile === null) {
                    $stats['missing_class']++;
                    $key = "{$file}|{$target}|missing_class";
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $items[] = ['kind' => 'missing_class', 'file' => $file, 'target' => $target];
                    }
                    continue;
                }

                if ($t['method'] !== null) {
                    $targetCode = (string)file_get_contents($targetFile);
                    if (!preg_match('/\\bfunction\\s+' . preg_quote($t['method'], '/') . '\\s*\\(/', $targetCode)) {
                        $stats['missing_method']++;
                        $key = "{$file}|{$target}|missing_method";
                        if (!isset($seen[$key])) {
                            $seen[$key] = true;
                            $items[] = ['kind' => 'missing_method', 'file' => $file, 'target' => $target, 'target_file' => $targetFile];
                        }
                    }
                }
            }
        }

        return ['items' => $items, 'stats' => $stats];
    }

    /**
     * @return list<array{class:string,method:?string}>
     */
    protected static function extractSeeTargets(string $code): array
    {
        $out = [];
        if (!preg_match_all('/@see\\s+\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)(?:::([A-Za-z_][A-Za-z0-9_]*)\\(\\))?/m', $code, $m, PREG_SET_ORDER)) {
            return $out;
        }
        foreach ($m as $mm) {
            $cls = ltrim($mm[1], '\\');
            if ($cls === '' || !str_contains($cls, '\\')) {
                continue;
            }
            $out[] = ['class' => $cls, 'method' => ($mm[2] ?? '') !== '' ? $mm[2] : null];
        }
        return $out;
    }

    protected static function extractFqcn(string $file): ?string
    {
        $code = (string)file_get_contents($file);
        $namespace = null;
        $name = null;

        $lines = preg_split('/\\R/', $code) ?: [];
        $limit = min(count($lines), 450);

        for ($i = 0; $i < $limit; $i++) {
            $line = trim((string)$lines[$i]);
            if ($namespace === null && preg_match('/^namespace\\s+([^;]+);/', $line, $m)) {
                $namespace = trim($m[1]);
                continue;
            }
            if ($name === null && preg_match('/^(?:final\\s+|abstract\\s+)?(class|interface|trait|enum)\\s+(\\w+)/', $line, $m)) {
                $name = $m[2];
                break;
            }
        }

        if ($namespace !== null && $name !== null) {
            return $namespace . '\\' . $name;
        }
        return null;
    }

    /** @return iterable<string> absolute file paths */
    protected static function iterPhpFiles(string $root): iterable
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $fi) {
            /** @var SplFileInfo $fi */
            if (!$fi->isFile()) {
                continue;
            }
            if ($fi->getExtension() !== 'php') {
                continue;
            }
            yield $fi->getPathname();
        }
    }
}

exit(PhpdocSeeValidate::main($argv));
