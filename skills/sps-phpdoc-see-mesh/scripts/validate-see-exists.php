#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Resolve PHPDoc @see targets against the monorepo via PSR-4 (no full Composer autoload).
 *
 * Usage:
 *   php skills/sps-phpdoc-see-mesh/scripts/validate-see-exists.php [scan-root] [--all]
 *
 * scan-root: directory tree to search for `* @see` (default: packages)
 *
 * Default mode: only validates targets whose symbol contains `\Switon\` (repo packages).
 * `--all`: also attempt every other tag shape (noisy: PSR/vendor, URLs, bare names).
 */

$args = array_slice($argv, 1);
$all = false;
$args = array_values(array_filter($args, static function ($a) use (&$all) {
    if ($a === '--all') {
        $all = true;

        return false;
    }

    return true;
}));
$scanRoot = $args[0] ?? 'packages';
$repoRoot = dirname(__DIR__, 3);
chdir($repoRoot);

if (!is_dir($scanRoot)) {
    fwrite(STDERR, "validate-see-exists: not a directory: {$scanRoot}\n");
    exit(2);
}

$nsToDir = [];
foreach (glob('packages/*/composer.json') ?: [] as $pj) {
    $j = json_decode((string) file_get_contents($pj), true);
    if (!is_array($j) || !isset($j['autoload']['psr-4'])) {
        continue;
    }
    $base = dirname($pj) . '/';
    foreach ($j['autoload']['psr-4'] as $prefix => $rel) {
        $nsToDir[rtrim((string) $prefix, '\\')] = $base . trim((string) $rel, '/');
    }
}

$resolveFile = static function (string $class) use ($nsToDir): ?string {
    $class = ltrim($class, '\\');
    $best = null;
    $bestLen = 0;
    foreach ($nsToDir as $ns => $dir) {
        if (str_starts_with($class, $ns) && strlen($ns) > $bestLen) {
            $best = $ns;
            $bestLen = strlen($ns);
        }
    }
    if ($best === null) {
        return null;
    }
    $suffix = substr($class, strlen($best));
    $suffix = str_replace('\\', '/', $suffix);

    return $nsToDir[$best] . $suffix . '.php';
};

$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS)
);
$refs = [];
foreach ($iter as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $text = (string) file_get_contents($f->getPathname());
    if (!str_contains($text, '@see')) {
        continue;
    }
    if (preg_match_all('/^\s*\* @see (\S+)/m', $text, $m)) {
        foreach ($m[1] as $raw) {
            $refs[$raw][$f->getPathname()] = true;
        }
    }
}

$issues = [
    'unparseable' => [],
    'missing_file' => [],
    'missing_method' => [],
    'missing_property' => [],
];
$skipped = ['non_switon' => 0, 'url' => 0, 'swoole' => 0, 'builtin' => 0];

/**
 * @return array{class: string, method: ?string, property: ?string}|null
 */
$parseSwiton = static function (string $raw): ?array {
    $raw = trim($raw);
    if (preg_match('/^\\\\?((?:\\\\)?Switon\\\\[\w\\\\]+)::(\w+)\(\)/', $raw, $m)) {
        return ['class' => trim($m[1], '\\'), 'method' => $m[2], 'property' => null];
    }
    if (preg_match('/^\\\\?((?:\\\\)?Switon\\\\[\w\\\\]+)::(\w+)/', $raw, $m)) {
        return ['class' => trim($m[1], '\\'), 'method' => $m[2], 'property' => null];
    }
    if (preg_match('/^\\\\?((?:\\\\)?Switon\\\\[\w\\\\]+)::\$(\w+)/', $raw, $m)) {
        return ['class' => trim($m[1], '\\'), 'method' => null, 'property' => $m[2]];
    }
    if (preg_match('/^\\\\?(Switon\\\\[\w\\\\]+)$/', $raw, $m)) {
        return ['class' => $m[1], 'method' => null, 'property' => null];
    }

    return null;
};

foreach (array_keys($refs) as $raw) {
    if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
        $skipped['url']++;

        continue;
    }
    if (str_starts_with($raw, 'debug_backtrace')) {
        $skipped['builtin']++;

        continue;
    }

    if (!$all && !str_contains($raw, 'Switon\\')) {
        $skipped['non_switon']++;

        continue;
    }

    $parsed = $parseSwiton($raw);
    if ($parsed === null) {
        if ($all) {
            $issues['unparseable'][$raw] = array_keys($refs[$raw]);
        } else {
            $skipped['non_switon']++;
        }

        continue;
    }

    ['class' => $class, 'method' => $method, 'property' => $prop] = $parsed;

    if (str_starts_with($class, 'Swoole\\')) {
        $skipped['swoole']++;

        continue;
    }

    $file = $resolveFile($class);
    if ($file === null || !is_file($file)) {
        $issues['missing_file'][$raw] = array_keys($refs[$raw]);

        continue;
    }

    $content = (string) file_get_contents($file);

    if ($method !== null) {
        if (!preg_match('/\bfunction\s+' . preg_quote($method, '/') . '\s*\(/', $content)) {
            $issues['missing_method'][$raw] = ['file' => $file, 'sources' => array_keys($refs[$raw])];
        }
    }

    if ($prop !== null) {
        if (!preg_match('/(?:public|protected|private)\s+(?:readonly\s+)?(?:static\s+)?[\w\\\\]+\s+\$' . preg_quote($prop, '/') . '\b/', $content)) {
            $issues['missing_property'][$raw] = ['file' => $file, 'sources' => array_keys($refs[$raw])];
        }
    }
}

$total = count($refs);
$problem =
    count($issues['unparseable'])
    + count($issues['missing_file'])
    + count($issues['missing_method'])
    + count($issues['missing_property']);

echo 'validate-see-exists: scan=' . $scanRoot . ' unique @see=' . $total;
echo $all ? " mode=all\n" : " mode=switon-only\n";
echo '(skipped: non-Switon/unscoped=' . $skipped['non_switon'] . ', url=' . $skipped['url']
    . ', builtin=' . $skipped['builtin'] . ', Swoole=' . $skipped['swoole'] . ")\n";

if ($problem === 0) {
    echo "OK: all Switon @see targets in scope resolve to PHP under packages/.\n";
    exit(0);
}

foreach ($issues['unparseable'] as $raw => $sources) {
    echo "\n[unparseable] {$raw}\n";
    foreach ($sources as $s) {
        echo "  {$s}\n";
    }
}
foreach ($issues['missing_file'] as $raw => $sources) {
    echo "\n[missing type] {$raw}\n";
    foreach ($sources as $s) {
        echo "  {$s}\n";
    }
}
foreach ($issues['missing_method'] as $raw => $info) {
    echo "\n[missing method] {$raw}\n";
    echo '  file: ' . $info['file'] . "\n";
    foreach ($info['sources'] as $s) {
        echo "  {$s}\n";
    }
}
foreach ($issues['missing_property'] as $raw => $info) {
    echo "\n[missing property] {$raw}\n";
    echo '  file: ' . $info['file'] . "\n";
    foreach ($info['sources'] as $s) {
        echo "  {$s}\n";
    }
}

exit(1);
