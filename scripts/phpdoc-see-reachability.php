#!/usr/bin/env php
<?php
/**
 * One-off: from entry classes, follow @see for 2 hops and count reachable FQCNs.
 * Run from repo root: php scripts/phpdoc-see-reachability.php
 */

$packagesDir = __DIR__ . '/../packages';
if (!is_dir($packagesDir)) {
    echo "Run from repo root\n";
    exit(1);
}

function extractSeeFqcns(string $content): array
{
    $targets = [];
    if (preg_match_all('/@see\s+\\\\?([^\s\*\)\$]+)/', $content, $m)) {
        foreach ($m[1] as $ref) {
            $ref = trim($ref);
            if (str_contains($ref, '::')) {
                $ref = explode('::', $ref)[0];
            }
            if (str_contains($ref, '$')) {
                $ref = explode('$', $ref)[0];
            }
            if ($ref === '' || str_starts_with($ref, 'http')) {
                continue;
            }
            $targets[] = trim($ref, '\\');
        }
    }
    return array_unique($targets);
}

function getFqcnFromFile(string $path): string
{
    $content = @file_get_contents($path);
    if (!$content || !preg_match('/namespace\s+([^;]+);/', $content, $m)) {
        return '';
    }

    return trim($m[1]) . '\\' . basename($path, '.php');
}

$graph = [];
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packagesDir, FilesystemIterator::SKIP_DOTS));
foreach ($iter as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    if (str_contains($path, '/tests/') || str_contains($path, '/vendor/')) {
        continue;
    }

    $fqcn = getFqcnFromFile($path);
    if ($fqcn === '') {
        continue;
    }

    $graph[$fqcn] = extractSeeFqcns(file_get_contents($path));
}

$entries = [
    'Switon\\Core\\Contract\\RandomInterface',
    'Switon\\Http\\Event\\RequestBegin',
    'Switon\\Db\\Exception\\ConnectionException',
];

echo "=== @see mesh reachability (2 hops from entry) ===\n\n";

foreach ($entries as $entry) {
    $entryNorm = trim($entry, '\\');
    $seen = [$entryNorm => true];
    $front = [$entryNorm];

    for ($hops = 0; $hops < 2; $hops++) {
        $next = [];
        foreach ($front as $current) {
            foreach ($graph[$current] ?? [] as $target) {
                $targetNorm = trim($target, '\\');
                if (!isset($seen[$targetNorm])) {
                    $seen[$targetNorm] = true;
                    $next[] = $targetNorm;
                }
            }
        }
        $front = $next;
    }

    $total = count($seen);
    $name = substr($entryNorm, strrpos($entryNorm, '\\') + 1);
    echo "[$name] reachable in 2 hops: $total nodes\n";
    $list = array_slice(array_keys($seen), 0, 12);
    echo "  e.g. " . implode(', ', $list) . "\n\n";
}

echo "Done.\n";
