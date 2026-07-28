#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * List interface files whose class-level PHPDoc contains too many method-level @see entries.
 *
 * This is intended as a fast pre-filter: it only scans the first docblock directly
 * preceding the `interface` declaration and counts patterns like:
 *   @see \Switon\Foo\BarInterface::method()
 *
 * Usage:
 *   ./skills/sps-framework-optimize/scripts/phpdoc-see-filter.php
 *   ./skills/sps-framework-optimize/scripts/phpdoc-see-filter.php --min=3
 *   ./skills/sps-framework-optimize/scripts/phpdoc-see-filter.php --json
 *   ./skills/sps-framework-optimize/scripts/phpdoc-see-filter.php --package=orm
 */

class PhpdocSeeFilter
{
    protected const DEFAULT_PACKAGES_DIR = 'packages';

    public function run(array $argv): int
    {
        $opts = $this->parseArgs($argv);

        $repoRoot = $opts['repoRoot'];
        $packagesDir = $repoRoot . DIRECTORY_SEPARATOR . $opts['packagesDir'];
        $min = $opts['min'];

        $report = $this->scanInterfaces($packagesDir, $opts['package']);

        // sort by count desc, then path asc
        uasort(
            $report,
            static function (array $a, array $b): int {
                $cmp = ($b['count'] <=> $a['count']);
                if ($cmp !== 0) {
                    return $cmp;
                }
                return strcmp($a['path'], $b['path']);
            }
        );

        // filter by min
        $filtered = [];
        foreach ($report as $path => $row) {
            if ($row['count'] >= $min) {
                $filtered[$path] = $row;
            }
        }

        if ($opts['json'] === true) {
            echo json_encode(array_values($filtered), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            return count($filtered) === 0 ? 0 : 1;
        }

        if (count($filtered) === 0) {
            echo "OK: no interface class-level @see entries >= {$min}.\n";
            return 0;
        }

        foreach ($filtered as $row) {
            echo $row['count'] . "\t" . $row['path'] . "\n";
            foreach ($row['matches'] as $m) {
                echo "  - L{$m['line']}: {$m['text']}\n";
            }
        }

        return 1;
    }

    /**
     * @return array{repoRoot:string,packagesDir:string,json:bool,min:int,package:?string}
     */
    protected function parseArgs(array $argv): array
    {
        $repoRoot = $this->detectRepoRoot(getcwd() ?: '.');
        $packagesDir = self::DEFAULT_PACKAGES_DIR;
        $json = false;
        $min = 3;
        $package = null;

        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--json') {
                $json = true;
                continue;
            }
            if ($arg === '--help' || $arg === '-h') {
                $this->printHelp();
                exit(0);
            }
            if (str_starts_with($arg, '--repo-root=')) {
                $repoRoot = (string)substr($arg, strlen('--repo-root='));
                continue;
            }
            if (str_starts_with($arg, '--packages-dir=')) {
                $packagesDir = (string)substr($arg, strlen('--packages-dir='));
                continue;
            }
            if (str_starts_with($arg, '--min=')) {
                $min = (int)substr($arg, strlen('--min='));
                continue;
            }
            if (str_starts_with($arg, '--package=')) {
                $package = (string)substr($arg, strlen('--package='));
                continue;
            }

            fwrite(STDERR, "Unknown argument: {$arg}\n");
            $this->printHelp();
            exit(2);
        }

        if ($min < 1) {
            $min = 1;
        }

        return [
            'repoRoot' => $repoRoot,
            'packagesDir' => $packagesDir,
            'json' => $json,
            'min' => $min,
            'package' => $package,
        ];
    }

    protected function printHelp(): void
    {
        echo "Scan interface class-level PHPDoc for method-level @see entries.\n\n";
        echo "Usage:\n";
        echo "  ./skills/sps-framework-optimize/scripts/phpdoc-see-filter.php [options]\n\n";
        echo "Options:\n";
        echo "  --min=N              Only show files whose class-level matches are >= N (default: 3)\n";
        echo "  --package=NAME       Only scan one package dir under packages/ (e.g. orm, http)\n";
        echo "  --json               Output as JSON (exit 1 if any matches)\n";
        echo "  --repo-root=PATH     Repo root (default: auto-detected)\n";
        echo "  --packages-dir=PATH  Packages dir relative to repo root (default: packages)\n";
        echo "  -h, --help           Show this help\n";
    }

    protected function detectRepoRoot(string $start): string
    {
        $dir = realpath($start) ?: $start;
        while (true) {
            $packages = $dir . DIRECTORY_SEPARATOR . self::DEFAULT_PACKAGES_DIR;
            if (is_dir($packages)) {
                $candidate = $packages . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'composer.json';
                $matches = glob($candidate) ?: [];
                if (count($matches) > 0) {
                    return $dir;
                }
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                return realpath($start) ?: $start;
            }
            $dir = $parent;
        }
    }

    /**
     * @return array<string, array{path:string,count:int,matches:list<array{line:int,text:string}>}>
     */
    protected function scanInterfaces(string $packagesDir, ?string $package): array
    {
        $roots = [];
        if ($package !== null) {
            $roots[] = rtrim($packagesDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $package;
        } else {
            $dirs = glob(rtrim($packagesDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
            sort($dirs);
            $roots = $dirs;
        }

        $report = [];

        foreach ($roots as $root) {
            $src = $root . DIRECTORY_SEPARATOR . 'src';
            if (!is_dir($src)) {
                continue;
            }

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                /** @var SplFileInfo $file */
                if (!$file->isFile()) {
                    continue;
                }
                if (!str_ends_with($file->getFilename(), 'Interface.php')) {
                    continue;
                }

                $path = $file->getPathname();
                $content = @file_get_contents($path);
                if ($content === false) {
                    continue;
                }

                $doc = $this->extractInterfaceDocblock($content);
                if ($doc === null) {
                    continue;
                }

                $matches = $this->findSeeMethodRefs($doc);
                if ($matches === []) {
                    continue;
                }

                $report[$path] = [
                    'path' => $path,
                    'count' => count($matches),
                    'matches' => $matches,
                ];
            }
        }

        return $report;
    }

    /**
     * Extract the first docblock immediately preceding the interface declaration.
     */
    protected function extractInterfaceDocblock(string $php): ?string
    {
        $re = '/(\/\*\*[\s\S]*?\*\/)\s*(?:#[^\n]*\n\s*)*interface\s+[A-Za-z0-9_]+\b/m';
        if (!preg_match($re, $php, $m)) {
            return null;
        }
        return $m[1] ?? null;
    }

    /**
     * @return list<array{line:int,text:string}>
     */
    protected function findSeeMethodRefs(string $docblock): array
    {
        $out = [];
        $lines = preg_split('/\R/', $docblock) ?: [];

        $re = '/@see\s+\\\\Switon\\\\[A-Za-z0-9_\\\\]+Interface::[A-Za-z0-9_]+\(\)/';

        foreach ($lines as $i => $line) {
            if (preg_match($re, $line) !== 1) {
                continue;
            }

            $out[] = [
                'line' => $i + 1,
                'text' => trim($line),
            ];
        }

        return $out;
    }
}

$tool = new PhpdocSeeFilter();
exit($tool->run($argv));

