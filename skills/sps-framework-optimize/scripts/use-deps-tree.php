#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build internal Switon package dependency graph from PHP `use Switon\...` statements.
 *
 * Source of truth for package ownership:
 * - packages/<pkg>/composer.json autoload.psr-4 namespace prefixes
 *
 * This tool is intentionally "cheap":
 * - only inspects `use` statements (fast, low false positives)
 * - outputs a graph; can render a BFS "tree" from a chosen root
 *
 * Usage:
 *   ./skills/sps-framework-optimize/scripts/use-deps-tree.php
 *   ./skills/sps-framework-optimize/scripts/use-deps-tree.php --json
 *   ./skills/sps-framework-optimize/scripts/use-deps-tree.php --dot
 *   ./skills/sps-framework-optimize/scripts/use-deps-tree.php --root=switon/orm
 *   ./skills/sps-framework-optimize/scripts/use-deps-tree.php --root=orm
 *   ./skills/sps-framework-optimize/scripts/use-deps-tree.php --root=switon/orm --max-depth=3
 *   ./skills/sps-framework-optimize/scripts/use-deps-tree.php --reverse --max-in=3
 *   ./skills/sps-framework-optimize/scripts/use-deps-tree.php --reverse --package=switon/query
 */

class UseDepsTree
{
    protected const DEFAULT_PACKAGES_DIR = 'packages';

    /** @var array<string, array{dir: string, composer: array<string,mixed>, name: string}> */
    protected array $packages = [];

    /** @var list<array{ns: string, package: string}> */
    protected array $nsMap = [];

    public function run(array $argv): int
    {
        $opts = $this->parseArgs($argv);

        $repoRoot = $opts['repoRoot'];
        $packagesDir = $repoRoot . DIRECTORY_SEPARATOR . $opts['packagesDir'];

        $this->loadPackages($packagesDir);
        $this->buildNamespaceMap();

        $graph = $this->buildPackageGraph();
        $reverse = $this->buildReverseGraph($graph);

        $root = $this->resolveRoot($opts['root']);
        $onlyPackage = $this->resolveRoot($opts['package']);

        if ($opts['json'] === true) {
            $payload = [
                'root' => $root,
                'graph' => $graph,
                'reverse' => $reverse,
                'tree' => $root !== null ? $this->buildBfsTree($graph, $root, $opts['maxDepth']) : null,
                'reverse_list' => $opts['reverse'] === true
                    ? $this->reverseList($reverse, $opts['maxIn'], $onlyPackage)
                    : null,
            ];
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            return 0;
        }

        if ($opts['dot'] === true) {
            echo $this->toDot($graph) . "\n";
            return 0;
        }

        if ($opts['reverse'] === true) {
            $this->printReverseList($reverse, $opts['maxIn'], $onlyPackage);
            return 0;
        }

        // default: print tree if root provided; otherwise print summary
        if ($root !== null) {
            $tree = $this->buildBfsTree($graph, $root, $opts['maxDepth']);
            $this->printTree($tree);
            return 0;
        }

        $this->printSummary($graph);
        return 0;
    }

    /**
     * @return array{repoRoot:string,packagesDir:string,json:bool,dot:bool,reverse:bool,root:?string,package:?string,maxDepth:int,maxIn:int}
     */
    protected function parseArgs(array $argv): array
    {
        $repoRoot = $this->detectRepoRoot(getcwd() ?: '.');
        $packagesDir = self::DEFAULT_PACKAGES_DIR;
        $json = false;
        $dot = false;
        $reverse = false;
        $root = null;
        $package = null;
        $maxDepth = 5;
        $maxIn = 3;

        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--json') {
                $json = true;
                continue;
            }
            if ($arg === '--dot') {
                $dot = true;
                continue;
            }
            if ($arg === '--reverse') {
                $reverse = true;
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
            if (str_starts_with($arg, '--root=')) {
                $root = (string)substr($arg, strlen('--root='));
                continue;
            }
            if (str_starts_with($arg, '--package=')) {
                $package = (string)substr($arg, strlen('--package='));
                continue;
            }
            if (str_starts_with($arg, '--max-depth=')) {
                $maxDepth = (int)substr($arg, strlen('--max-depth='));
                continue;
            }
            if (str_starts_with($arg, '--max-in=')) {
                $maxIn = (int)substr($arg, strlen('--max-in='));
                continue;
            }

            fwrite(STDERR, "Unknown argument: {$arg}\n");
            $this->printHelp();
            exit(2);
        }

        if ($maxDepth < 0) {
            $maxDepth = 0;
        }
        if ($maxIn < 0) {
            $maxIn = 0;
        }

        return [
            'repoRoot' => $repoRoot,
            'packagesDir' => $packagesDir,
            'json' => $json,
            'dot' => $dot,
            'reverse' => $reverse,
            'root' => $root,
            'package' => $package,
            'maxDepth' => $maxDepth,
            'maxIn' => $maxIn,
        ];
    }

    protected function printHelp(): void
    {
        echo "Build internal package dependency graph from PHP use statements.\n\n";
        echo "Usage:\n";
        echo "  ./skills/sps-framework-optimize/scripts/use-deps-tree.php [options]\n\n";
        echo "Options:\n";
        echo "  --root=PACKAGE        Print BFS tree from root (e.g. switon/orm or orm)\n";
        echo "  --max-depth=N         BFS max depth (default: 5)\n";
        echo "  --reverse             Print reverse dependency list (incoming edges)\n";
        echo "  --max-in=N            Reverse list: only show packages with incoming <= N (default: 3)\n";
        echo "  --package=PACKAGE     Reverse list: only show one package (e.g. switon/query or query)\n";
        echo "  --json                Output graph + tree as JSON\n";
        echo "  --dot                 Output full graph as DOT\n";
        echo "  --repo-root=PATH      Repo root (default: auto-detected)\n";
        echo "  --packages-dir=PATH   Packages dir relative to repo root (default: packages)\n";
        echo "  -h, --help            Show this help\n";
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

    protected function loadPackages(string $packagesDir): void
    {
        $glob = rtrim($packagesDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'composer.json';
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
                'name' => $name,
            ];
        }

        ksort($this->packages);
    }

    protected function buildNamespaceMap(): void
    {
        $map = [];
        foreach ($this->packages as $packageName => $info) {
            $composer = $info['composer'];
            $psr4 = (array)(($composer['autoload']['psr-4'] ?? []) ?: []);
            foreach ($psr4 as $nsPrefix => $_path) {
                if (!is_string($nsPrefix)) {
                    continue;
                }
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
     * @return array<string, list<string>> package => used package list
     */
    protected function buildPackageGraph(): array
    {
        $graph = [];

        foreach ($this->packages as $self => $info) {
            $src = $info['dir'] . DIRECTORY_SEPARATOR . 'src';
            if (!is_dir($src)) {
                continue;
            }

            $used = [];
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
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

                foreach ($this->extractSwitonFqcnsFromUse($content) as $fqcn) {
                    $owner = $this->ownerPackage($fqcn);
                    if ($owner === null || $owner === $self) {
                        continue;
                    }
                    $used[$owner] = true;
                }
            }

            $deps = array_keys($used);
            sort($deps);
            $graph[$self] = $deps;
        }

        ksort($graph);
        return $graph;
    }

    /**
     * @param array<string, list<string>> $graph
     * @return array<string, list<string>> package => incoming package list
     */
    protected function buildReverseGraph(array $graph): array
    {
        $reverse = [];
        foreach (array_keys($this->packages) as $pkg) {
            $reverse[$pkg] = [];
        }

        foreach ($graph as $from => $tos) {
            foreach ($tos as $to) {
                $reverse[$to][] = $from;
            }
        }

        foreach ($reverse as &$ins) {
            $ins = array_values(array_unique($ins));
            sort($ins);
        }
        ksort($reverse);
        return $reverse;
    }

    /**
     * Extract likely “real” references (avoid matching arbitrary strings):
     * - use Switon\...;
     * - use Switon\Foo\{Bar, Baz as Qux};
     *
     * @return list<string> fqcn-like tokens (may or may not have leading backslash)
     */
    protected function extractSwitonFqcnsFromUse(string $php): array
    {
        $matches = [];

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

        $uniq = [];
        foreach ($matches as $m) {
            $uniq[$m] = true;
        }
        return array_keys($uniq);
    }

    protected function ownerPackage(string $fqcn): ?string
    {
        $normalized = ltrim($fqcn, '\\');
        foreach ($this->nsMap as $row) {
            if (str_starts_with($normalized, $row['ns'])) {
                return $row['package'];
            }
        }
        return null;
    }

    protected function resolveRoot(?string $root): ?string
    {
        if ($root === null || $root === '') {
            return null;
        }
        if (isset($this->packages[$root])) {
            return $root;
        }
        // allow shorthand: "orm" -> "switon/orm" when unique
        $needle = strtolower($root);
        $candidates = [];
        foreach (array_keys($this->packages) as $pkg) {
            if (str_ends_with(strtolower($pkg), '/' . $needle)) {
                $candidates[] = $pkg;
            }
        }
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * @param array<string, list<string>> $reverse
     * @return list<array{package:string,incoming:int,consumers:list<string>}>
     */
    protected function reverseList(array $reverse, int $maxIn, ?string $onlyPackage): array
    {
        $rows = [];
        foreach ($reverse as $pkg => $consumers) {
            if ($onlyPackage !== null && $pkg !== $onlyPackage) {
                continue;
            }
            $n = count($consumers);
            if ($maxIn > 0 && $n > $maxIn) {
                continue;
            }
            if ($onlyPackage === null && $n === 0) {
                continue;
            }
            $rows[] = [
                'package' => $pkg,
                'incoming' => $n,
                'consumers' => $consumers,
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => ($a['incoming'] <=> $b['incoming']) ?: strcmp($a['package'], $b['package'])
        );
        return $rows;
    }

    /**
     * @param array<string, list<string>> $reverse
     */
    protected function printReverseList(array $reverse, int $maxIn, ?string $onlyPackage): void
    {
        $rows = $this->reverseList($reverse, $maxIn, $onlyPackage);
        if ($rows === []) {
            echo "OK: no reverse edges matched.\n";
            return;
        }
        foreach ($rows as $row) {
            echo $row['incoming'] . "\t" . $row['package'] . "\n";
            foreach ($row['consumers'] as $c) {
                echo "  - " . $c . "\n";
            }
        }
    }

    /**
     * @param array<string, list<string>> $graph
     * @return array{root:string,levels:array<int,list<string>>,edges:array<string,list<string>>}
     */
    protected function buildBfsTree(array $graph, string $root, int $maxDepth): array
    {
        $seen = [$root => true];
        $levels = [0 => [$root]];
        $edges = [$root => []];

        $queue = [[$root, 0]];
        while ($queue !== []) {
            [$node, $depth] = array_shift($queue);
            if ($depth >= $maxDepth) {
                continue;
            }

            $children = $graph[$node] ?? [];
            foreach ($children as $child) {
                if (!isset($edges[$node])) {
                    $edges[$node] = [];
                }
                $edges[$node][] = $child;

                if (isset($seen[$child])) {
                    continue;
                }
                $seen[$child] = true;
                $levels[$depth + 1] ??= [];
                $levels[$depth + 1][] = $child;
                $queue[] = [$child, $depth + 1];
            }
        }

        // stable sort
        foreach ($levels as &$lvl) {
            sort($lvl);
        }
        foreach ($edges as &$kids) {
            sort($kids);
        }

        return [
            'root' => $root,
            'levels' => $levels,
            'edges' => $edges,
        ];
    }

    /**
     * @param array{root:string,levels:array<int,list<string>>,edges:array<string,list<string>>} $tree
     */
    protected function printTree(array $tree): void
    {
        $root = $tree['root'];
        echo $root . "\n";

        $printed = [$root => true];
        $this->printTreeNode($root, $tree['edges'], $printed, '');
    }

    /**
     * @param array<string, list<string>> $edges
     * @param array<string, bool> $printed
     */
    protected function printTreeNode(string $node, array $edges, array &$printed, string $prefix): void
    {
        $kids = $edges[$node] ?? [];
        $count = count($kids);
        foreach ($kids as $i => $child) {
            $isLast = ($i === $count - 1);
            $branch = $isLast ? '└─ ' : '├─ ';
            echo $prefix . $branch . $child;
            if (isset($printed[$child])) {
                echo "  (seen)\n";
                continue;
            }
            echo "\n";

            $printed[$child] = true;
            $nextPrefix = $prefix . ($isLast ? '   ' : '│  ');
            $this->printTreeNode($child, $edges, $printed, $nextPrefix);
        }
    }

    /**
     * @param array<string, list<string>> $graph
     */
    protected function printSummary(array $graph): void
    {
        $totalEdges = 0;
        $maxOut = 0;
        $maxPkg = null;

        foreach ($graph as $pkg => $deps) {
            $out = count($deps);
            $totalEdges += $out;
            if ($out > $maxOut) {
                $maxOut = $out;
                $maxPkg = $pkg;
            }
        }

        echo "packages=" . count($graph) . " edges={$totalEdges}\n";
        if ($maxPkg !== null) {
            echo "max_out_degree={$maxOut} package={$maxPkg}\n";
        }
        echo "Tip: run with --root=switon/orm (or shorthand --root=orm)\n";
    }

    /**
     * @param array<string, list<string>> $graph
     */
    protected function toDot(array $graph): string
    {
        $lines = [];
        $lines[] = 'digraph switon_deps {';
        $lines[] = '  rankdir=LR;';
        $lines[] = '  node [shape=box, fontsize=10];';

        foreach ($graph as $from => $tos) {
            if ($tos === []) {
                continue;
            }
            foreach ($tos as $to) {
                $lines[] = '  "' . $from . '" -> "' . $to . '";';
            }
        }

        $lines[] = '}';
        return implode("\n", $lines);
    }
}

$tool = new UseDepsTree();
exit($tool->run($argv));

