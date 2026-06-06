<?php

declare(strict_types=1);

namespace Switon\Beacon\Command;

use Switon\Command\Attribute\Hidden;
use Switon\Command\Attribute\Tool;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\Core\Exception\JsonException;
use Switon\Core\FilesystemInterface;
use Switon\Core\Json;
use Switon\Core\PathAliasInterface;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Package list tools: official (install ↔ namespace), later e.g. recommended.
 *
 * @see packages:official
 */
class PackagesCommand
{
    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected PathAliasInterface $pathAlias;

    /**
     * List official packages: install name, namespace, keywords (clues for AI).
     *
     * By default includes packages[].classes when present.
     * Use `--no-classes` to omit it.
     */
    #[Hidden, Tool('Returns JSON: packages[] → install, namespace, keywords, classes (unless --no-classes).')]
    public function officialAction(bool $classes = true): int
    {
        $path = $this->officialPackagesPath();
        $packages = $this->readPackages($path);
        if ($packages === null) {
            return 1;
        }

        if (!$classes) {
            foreach ($packages as $i => $package) {
                if (is_array($package)) {
                    unset($packages[$i]['classes']);
                }
            }
        }

        $this->console->writeLn(Json::stringify(['packages' => $packages]));
        return 0;
    }

    /**
     * Sync official packages with short class names per package (CLI only).
     *
     * - Scans packages/{name}/src for PHP files.
     * - Uses PHP file paths under src/ (e.g. Engine/Sword/Compiler.php).
     * - Trims a trailing "Interface" suffix from the filename part (FooInterface.php → Foo.php).
     * - Writes unique, sorted paths into official-packages.json as classes (space-separated string).
     */
    public function syncClassesAction(): int
    {
        $dataPath = $this->officialPackagesPath();
        $packages = $this->readPackages($dataPath);
        if ($packages === null) {
            return 1;
        }

        $packagesRoot = $this->packagesVendorRoot();

        foreach ($packages as $index => $package) {
            if (!is_array($package)) {
                continue;
            }
            $install = $package['install'] ?? null;
            $namespace = $package['namespace'] ?? null;

            if (!is_string($install) || !is_string($namespace) || $namespace === '—') {
                unset($packages[$index]['classes']);
                continue;
            }

            $parts = explode('/', $install, 2);
            if (count($parts) !== 2 || $parts[0] !== 'switon') {
                unset($packages[$index]['classes']);
                continue;
            }

            $packageName = $parts[1];
            $srcDir = $packagesRoot . '/' . $packageName . '/src';
            if (!is_dir($srcDir)) {
                unset($packages[$index]['classes']);
                continue;
            }

            $paths = $this->collectClassPaths($srcDir);
            $paths = array_values(array_unique($paths));
            sort($paths, SORT_STRING);
            $packages[$index]['classes'] = $paths === [] ? '' : implode(' ', $paths);
        }

        $json = Json::stringify(array_values($packages), JSON_PRETTY_PRINT);

        if (!$this->writePackages($dataPath, $json . PHP_EOL)) {
            $this->console->writeLn(Json::stringify(['error' => 'failed to write data']));
            return 1;
        }

        $this->console->writeLn(Json::stringify(['status' => 'ok']));
        return 0;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function readPackages(string $path): ?array
    {
        if (!$this->filesystem->exists($path)) {
            $this->console->writeLn(Json::stringify(['error' => 'data file not found']));
            return null;
        }

        try {
            $json = $this->filesystem->read($path);
        } catch (Throwable $e) {
            $this->console->writeLn(Json::stringify(['error' => 'failed to read data']));
            return null;
        }

        try {
            $packages = Json::parse($json);
        } catch (JsonException $e) {
            $this->console->writeLn(Json::stringify(['error' => 'invalid data']));
            return null;
        }

        if (!is_array($packages)) {
            $this->console->writeLn(Json::stringify(['error' => 'invalid data']));
            return null;
        }

        return $packages;
    }

    protected function writePackages(string $path, string $json): bool
    {
        try {
            $this->filesystem->write($path, $json);
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    protected function officialPackagesPath(): string
    {
        return $this->pathAlias->resolve('@switon.beacon.resources/data/official-packages.json');
    }

    protected function packagesVendorRoot(): string
    {
        return $this->pathAlias->resolve('@vendor/switon');
    }

    /**
     * Collect class-like PHP file paths under src dir.
     *
     * - Uses src/ relative paths (without .php).
     * - Trims a trailing "Interface" suffix from the filename part (FooInterface.php → Foo.php).
     *
     * @return array<int, string>
     */
    protected function collectClassPaths(string $srcDir): array
    {
        $paths = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $basename = $file->getBasename('.php');

            // Only keep plausible class-like names: start with uppercase letter, no dashes.
            if ($basename === '' || preg_match('/^[A-Z]\w*$/', $basename) !== 1) {
                continue;
            }

            if (str_ends_with($basename, 'Interface')) {
                $basename = substr($basename, 0, -9);
                if ($basename === '') {
                    continue;
                }
            }

            $dir = $file->getPath();
            if (!str_starts_with($dir, $srcDir)) {
                continue;
            }

            $relativeDir = substr($dir, strlen($srcDir));
            $relativeDir = ltrim(str_replace('\\', '/', $relativeDir), '/');
            $relativePath = $relativeDir === '' ? $basename : "$relativeDir/$basename";

            $paths[] = $relativePath;
        }

        return $paths;
    }
}
