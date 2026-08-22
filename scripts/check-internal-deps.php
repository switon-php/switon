#!/usr/bin/env php
<?php

/**
 * Infer missing and extra internal composer dependencies by scanning PHP files for Switon\... usages.
 *
 * Logic:
 * - Build a namespace-prefix -> package-name map from packages/<package>/composer.json (autoload.psr-4)
 * - Scan each package's src/ for references:
 *   - use Switon\...;
 * - Map referenced FQCNs to owning package via the PSR-4 prefix map
 * - Compare against composer.json require
 *
 * Usage:
 *   ./scripts/check-internal-deps.php
 *   ./scripts/check-internal-deps.php --json
 *   ./scripts/check-internal-deps.php --package=switon/http
 */

final class InternalDepChecker
{
    private const DEFAULT_PACKAGES_DIR = 'packages';

    /** @var array<string, array{dir: string, composer: array<string,mixed>}> */
    private array $packages = [];

    /** @var list<array{ns: string, package: string}> */
    private array $nsMap = [];

    public function run(array $argv): int
    {
        $opts = $this->parseArgs($argv);

        $repoRoot = $opts['repoRoot'];
        $packagesDir = $repoRoot . DIRECTORY_SEPARATOR . $opts['packagesDir'];

        $this->loadPackages($packagesDir);
        $this->buildNamespaceMap();

        if (($opts['debug'] ?? false) === true) {
            fwrite(STDERR, "[debug] repoRoot={$repoRoot}\n");
            fwrite(STDERR, "[debug] packagesDir={$packagesDir}\n");
            fwrite(STDERR, "[debug] packagesLoaded=" . count($this->packages) . "\n");
            fwrite(STDERR, "[debug] nsPrefixesLoaded=" . count($this->nsMap) . "\n");
        }

        $targetPackage = $opts['package'];

        $report = [];
        foreach ($this->packages as $packageName => $info) {
            if ($targetPackage !== null && $packageName !== $targetPackage) {
                continue;
            }

            $pkgDir = $info['dir'];
            $composer = $info['composer'];

            $declaredRequire = array_fill_keys(
                array_keys((array)($composer['require'] ?? [])),
                true
            );

            $usedSrc = $this->scanPackageDir($pkgDir . DIRECTORY_SEPARATOR . 'src', $packageName);
            $declaredInternal = $this->filterInternalDeclared(array_keys($declaredRequire));

            $missingRequire = [];
            foreach ($usedSrc as $dep) {
                if (!isset($declaredRequire[$dep])) {
                    $missingRequire[] = $dep;
                }
            }
            sort($missingRequire);

            $extraRequire = [];
            foreach ($declaredInternal as $dep) {
                if (!in_array($dep, $usedSrc, true)) {
                    $extraRequire[] = $dep;
                }
            }
            sort($extraRequire);

            if ($missingRequire === [] && $extraRequire === []) {
                continue;
            }

            $report[$packageName] = [
                'missing_require' => $missingRequire,
                'extra_require' => $extraRequire,
                'used_internal_src' => $usedSrc,
                'declared_internal' => $declaredInternal,
            ];
        }

        if ($opts['json']) {
            echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            return count($report) === 0 ? 0 : 1;
        }

        if (count($report) === 0) {
            echo "OK: no missing internal dependencies found.\n";
            return 0;
        }

        $useColor = (bool)($opts['color'] ?? false);

        foreach (array_keys($report) as $pkg) {
            $row = $report[$pkg];

            $pkgLabel = $useColor ? $this->c($pkg, 'cyan') : $pkg;

            $parts = [];

            if ($row['missing_require'] !== []) {
                $deps = $useColor
                    ? implode(', ', array_map(fn (string $d): string => $this->c($d, 'red'), $row['missing_require']))
                    : implode(', ', $row['missing_require']);

                $label = $useColor ? $this->c('missing', 'red') : 'missing';
                $parts[] = $label . ': ' . $deps;
            }

            if (($row['extra_require'] ?? []) !== []) {
                $deps = $useColor
                    ? implode(', ', array_map(fn (string $d): string => $this->c($d, 'yellow'), $row['extra_require']))
                    : implode(', ', $row['extra_require']);

                $label = $useColor ? $this->c('extra', 'yellow') : 'extra';
                $parts[] = $label . ': ' . $deps;
            }

            echo $pkgLabel . '  ' . implode('  ', $parts) . "\n";
        }

        return 1;
    }

    /**
     * @return array{repoRoot:string,packagesDir:string,json:bool,package:?string,debug:bool,color:bool}
     */
    private function parseArgs(array $argv): array
    {
        $repoRoot = $this->detectRepoRoot(getcwd() ?: '.');
        $packagesDir = self::DEFAULT_PACKAGES_DIR;
        $json = false;
        $package = null;
        $debug = false;
        $color = $this->shouldUseColor();

        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--json') {
                $json = true;
                continue;
            }
            if ($arg === '--debug') {
                $debug = true;
                continue;
            }
            if ($arg === '--no-color') {
                $color = false;
                continue;
            }
            if ($arg === '--color') {
                $color = true;
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
            if (str_starts_with($arg, '--package=')) {
                $package = (string)substr($arg, strlen('--package='));
                continue;
            }

            fwrite(STDERR, "Unknown argument: {$arg}\n");
            $this->printHelp();
            exit(2);
        }

        return [
            'repoRoot' => $repoRoot,
            'packagesDir' => $packagesDir,
            'json' => $json,
            'package' => $package,
            'debug' => $debug,
            'color' => $color,
        ];
    }

    private function printHelp(): void
    {
        echo "Infer missing/extra internal Switon composer dependencies by scanning src/ for use Switon\\... statements.\n\n";
        echo "Usage:\n";
        echo "  ./scripts/check-internal-deps.php [options]\n\n";
        echo "Options:\n";
        echo "  --json                Output full report as JSON (exit code: 1 if any missing)\n";
        echo "  --debug               Print resolved repo paths and loaded counts to STDERR\n";
        echo "  --color               Force color output\n";
        echo "  --no-color            Disable color output\n";
        echo "  --package=NAME        Only check one package (e.g. switon/http)\n";
        echo "  --repo-root=PATH      Repo root (default: auto-detected from cwd; can override)\n";
        echo "  --packages-dir=PATH   Packages dir relative to repo root (default: packages)\n";
        echo "  -h, --help            Show this help\n";
    }

    private function shouldUseColor(): bool
    {
        // Respect NO_COLOR (https://no-color.org/)
        if (getenv('NO_COLOR') !== false) {
            return false;
        }
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDOUT);
        }
        // Best-effort fallback
        return getenv('TERM') !== false && getenv('TERM') !== 'dumb';
    }

    private function c(string $text, string $color): string
    {
        $codes = [
            'red' => '0;31',
            'yellow' => '0;33',
            'cyan' => '0;36',
            'bold' => '1',
        ];
        $code = $codes[$color] ?? null;
        if ($code === null) {
            return $text;
        }
        return "\033[{$code}m{$text}\033[0m";
    }

    private function detectRepoRoot(string $start): string
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

    private function loadPackages(string $packagesDir): void
    {
        $glob = rtrim($packagesDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*'. DIRECTORY_SEPARATOR . 'composer.json';
        $files = glob($glob) ?: [];

        foreach ($files as $composerPath) {
            $pkgDir = dirname($composerPath);
            $json = file_get_contents($composerPath);
            if ($json === false) {
                continue;
            }

            /** @var array<string,mixed>|null $data */
            $data = json_decode($json, true);
            if (!is_array($data)) {
                continue;
            }

            $name = (string)($data['name'] ?? basename($pkgDir));
            $this->packages[$name] = [
                'dir' => $pkgDir,
                'composer' => $data,
            ];
        }

        ksort($this->packages);
    }

    private function buildNamespaceMap(): void
    {
        $map = [];

        foreach ($this->packages as $packageName => $info) {
            $composer = $info['composer'];
            $psr4 = (array)(($composer['autoload']['psr-4'] ?? []) ?: []);

            foreach ($psr4 as $nsPrefix => $_path) {
                if (!is_string($nsPrefix)) {
                    continue;
                }
                // normalize: remove leading backslashes
                $norm = ltrim($nsPrefix, '\\');
                if ($norm === '') {
                    continue;
                }
                $map[] = ['ns' => $norm, 'package' => $packageName];
            }
        }

        // longest prefix wins
        usort($map, static fn (array $a, array $b): int => strlen($b['ns']) <=> strlen($a['ns']));
        $this->nsMap = $map;
    }

    /**
     * @return list<string> package names
     */
    private function scanPackageDir(string $dir, string $selfPackage): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $used = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $content = @file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            foreach ($this->extractSwitonFqcns($content) as $fqcn) {
                $owner = $this->ownerPackage($fqcn);
                if ($owner === null || $owner === $selfPackage) {
                    continue;
                }
                $used[$owner] = true;
            }
        }

        $deps = array_keys($used);
        sort($deps);
        return $deps;
    }

    /**
     * Extract likely code references without matching comments or strings.
     *
     * Coverage:
     * - use Switon\...;
     * - extends / implements / ::class / new with qualified Switon names
     * - typed properties, params, returns, unions, and attributes when they contain qualified Switon names
     *
     * @return list<string> fqcn-like tokens (may or may not have leading backslash)
     */
    private function extractSwitonFqcns(string $php): array
    {
        $matches = [];

        // Match any single-line use statement that references Switon\..., including:
        // - use Switon\Foo\Bar;
        // - use Switon\Foo\Bar as Baz;
        // - use Switon\Foo\{Bar, Baz as Qux};
        $useLineRe = '/^\s*use\s+[^;]*\\\\?Switon\\\\[^;]*;/m';
        $fqcnRe = '/\\\\?Switon\\\\[A-Za-z0-9_\\\\]+/';

        if (preg_match_all($useLineRe, $php, $useMatches)) {
            foreach ($useMatches[0] as $line) {
                if (preg_match_all($fqcnRe, $line, $m)) {
                    foreach ($m[0] as $fqcn) {
                        $matches[] = $fqcn;
                    }
                }
            }
        }

        foreach (token_get_all($php) as $token) {
            if (!is_array($token)) {
                continue;
            }

            $type = $token[0];
            $text = $token[1];

            if (!in_array($type, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $normalized = ltrim($text, '\\');
            if (!str_starts_with($normalized, 'Switon\\')) {
                continue;
            }

            $matches[] = $text;
        }

        // de-dup
        $uniq = [];
        foreach ($matches as $m) {
            $uniq[$m] = true;
        }
        return array_keys($uniq);
    }

    private function ownerPackage(string $fqcn): ?string
    {
        $normalized = ltrim($fqcn, '\\');
        foreach ($this->nsMap as $row) {
            if (str_starts_with($normalized, $row['ns'])) {
                return $row['package'];
            }
        }
        return null;
    }

    /**
     * @param list<string> $declared
     * @return list<string>
     */
    private function filterInternalDeclared(array $declared): array
    {
        $internal = [];
        foreach ($declared as $pkg) {
            if (isset($this->packages[$pkg])) {
                $internal[] = $pkg;
            }
        }
        sort($internal);
        return $internal;
    }
}

$checker = new InternalDepChecker();
exit($checker->run($argv));
