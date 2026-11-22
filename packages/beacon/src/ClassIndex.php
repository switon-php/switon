<?php

declare(strict_types=1);

namespace Switon\Beacon;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Switon\Beacon\Exception\ClassIndexNotFoundException;
use Switon\Core\ClassName;
use Switon\Core\FilesystemInterface;
use Switon\Core\Path;
use Switon\Core\Runtime;
use ReflectionClass;
use Throwable;

use function array_diff;
use function array_flip;
use function array_map;
use function array_merge;
use function array_values;
use function basename;
use function get_declared_classes;
use function method_exists;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;

/**
 * AI-facing class index: app scanned on the fly; vendor from Composer classmap (no cache).
 *
 * Guidance: Use this for project-wide AI discovery; vendor classes depend on a fresh optimized Composer classmap.
 *
 * @see \Switon\Beacon\ClassIndexInterface
 * @see \Switon\Beacon\Command\ClassCommand
 */
class ClassIndex implements ClassIndexInterface
{
    /** @var list<string>|null Merged app + vendor; in-memory after first all(). */
    protected ?array $memoryCache = null;

    /** @var int|null Vendor class count after first all() or getVendorClassCount(). */
    protected ?int $vendorClassCount = null;

    protected FilesystemInterface $filesystem;

    public function __construct(FilesystemInterface $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * All FQCNs: app from live scan, vendor from Composer getClassMap(). Merged result cached in memory.
     *
     * @return list<string>
     *
     * @throws ClassIndexNotFoundException When ClassLoader has no registered loaders.
     */
    public function all(): array
    {
        if ($this->memoryCache !== null) {
            return $this->memoryCache;
        }
        $appClasses = $this->buildFromApp();
        $vendorClasses = $this->getVendorClassesFromComposer();
        $this->vendorClassCount = count($vendorClasses);
        if ($this->vendorClassCount < 20) {
            ClassIndexNotFoundException::raise('Class index unavailable. Run: composer dump-autoload -o');
        }
        $this->memoryCache = array_merge($appClasses, $vendorClasses);
        return $this->memoryCache;
    }

    public function getVendorClassCount(): int
    {
        if ($this->vendorClassCount !== null) {
            return $this->vendorClassCount;
        }
        $vendorClasses = $this->getVendorClassesFromComposer();
        $this->vendorClassCount = count($vendorClasses);
        return $this->vendorClassCount;
    }

    /** @return list<string> Vendor FQCNs from ClassLoader (filter /tests/ and require-dev). */
    protected function getVendorClassesFromComposer(): array
    {
        $this->ensureClassmapFresh();

        if (!method_exists(ClassLoader::class, 'getRegisteredLoaders')) {
            ClassIndexNotFoundException::raise('Class index unavailable. Run: composer dump-autoload -o');
        }

        $loaders = ClassLoader::getRegisteredLoaders();
        if ($loaders === [] || $loaders === null) {
            ClassIndexNotFoundException::raise('Class index unavailable. Run: composer dump-autoload -o');
        }

        $classMap = [];
        foreach ($loaders as $loader) {
            if ($loader instanceof ClassLoader) {
                $classMap = array_merge($classMap, $loader->getClassMap());
            }
        }

        $devPaths = $this->getDevPackagePaths();
        $out = [];

        foreach ($classMap as $fqcn => $path) {
            $pathNorm = str_replace('\\', '/', $path);
            if (str_contains($pathNorm, '/tests/')) {
                continue;
            }
            $underDev = false;
            foreach ($devPaths as $devPath) {
                if (str_starts_with($pathNorm, $devPath)) {
                    $underDev = true;
                    break;
                }
            }
            if ($underDev) {
                continue;
            }
            $out[] = $fqcn;
        }

        return $out;
    }

    /** @return list<string> Absolute path prefixes for require-dev packages (with trailing slash). */
    protected function getDevPackagePaths(): array
    {
        $paths = [];
        if (!class_exists(InstalledVersions::class)) {
            return $paths;
        }

        $installed = InstalledVersions::getAllRawData();
        if (!is_array($installed)) {
            return $paths;
        }

        $rootNorm = str_replace('\\', '/', Runtime::getRoot()) . '/';

        foreach ($installed as $dataset) {
            if (!isset($dataset['versions']) || !is_array($dataset['versions'])) {
                continue;
            }
            foreach ($dataset['versions'] as $name => $pkg) {
                if (empty($pkg['dev_requirement'])) {
                    continue;
                }
                try {
                    $installPath = InstalledVersions::getInstallPath($name);
                } catch (Throwable) {
                    continue;
                }
                if ($installPath === null || $installPath === '') {
                    continue;
                }
                $pathNorm = str_replace('\\', '/', $installPath);
                $abs = self::isAbsolutePath($pathNorm)
                    ? $pathNorm
                    : $rootNorm . $pathNorm;
                if (!str_ends_with($abs, '/')) {
                    $abs .= '/';
                }
                $paths[] = $abs;
            }
        }

        return $paths;
    }

    /** Fail when classmap missing or older than composer.lock. */
    protected function ensureClassmapFresh(): void
    {
        $classmapFile = '@vendor/composer/autoload_classmap.php';
        $lockFile = '@root/composer.lock';

        if (!$this->filesystem->exists($classmapFile)) {
            ClassIndexNotFoundException::raise('Class index unavailable. Run: composer dump-autoload -o');
        }
        $classmapMtime = $this->filesystem->mtime($classmapFile);
        $lockMtime = $this->filesystem->exists($lockFile) ? $this->filesystem->mtime($lockFile) : null;
        if ($classmapMtime !== null && $lockMtime !== null && $classmapMtime < $lockMtime) {
            ClassIndexNotFoundException::raise('Class index unavailable. Run: composer dump-autoload -o');
        }
    }

    /** Whether the path is absolute (Unix / or Windows C:). */
    protected static function isAbsolutePath(string $path): bool
    {
        return Path::isAbsolute($path);
    }

    /** @return list<string> App FQCNs; always scan @app (no cache). */
    protected function buildFromApp(): array
    {
        $files = $this->collectPhpFilesExcludingTests('@app');
        return $this->collectFqcnsFromFiles($files);
    }

    /**
     * @param list<string> $files
     *
     * @return list<string> Require $files and return FQCNs defined in them.
     */
    protected function collectFqcnsFromFiles(array $files): array
    {
        $files = array_values(array_unique(array_map(
            static fn (string $file): string => str_replace('\\', '/', $file),
            $files,
        )));
        if ($files === []) {
            return [];
        }
        $filesSet = array_flip($files);
        $expectedShortNames = array_flip(array_map(static fn (string $f): string => basename($f, '.php'), $files));
        $before = get_declared_classes();
        foreach ($files as $file) {
            require_once $file;
        }
        $after = get_declared_classes();
        $newClasses = array_diff($after, $before);
        $classes = [];
        foreach ($newClasses as $fqcn) {
            if (!str_contains($fqcn, '\\')) {
                continue;
            }
            if (!isset($expectedShortNames[ClassName::short($fqcn)])) {
                continue;
            }
            $ref = new ReflectionClass($fqcn);
            $file = $ref->getFileName();
            if ($file === false) {
                continue;
            }
            $real = $this->filesystem->realpath($file);
            $fileNorm = $real === null ? '' : str_replace('\\', '/', $real);
            if ($fileNorm !== '' && isset($filesSet[$fileNorm])) {
                $classes[] = $fqcn;
            }
        }
        return $classes;
    }

    /** @return list<string> PHP file paths under dir (alias), excluding paths containing /tests/. */
    protected function collectPhpFilesExcludingTests(string $dir): array
    {
        $out = [];
        foreach ($this->filesystem->files($dir) as $file) {
            $norm = str_replace('\\', '/', $file);
            if (str_ends_with($norm, '.php') && !str_contains($norm, '/tests/')) {
                $out[] = $norm;
            }
        }
        foreach ($this->filesystem->directories($dir) as $sub) {
            $subNorm = str_replace('\\', '/', $sub);
            if (str_contains($subNorm, '/tests/')) {
                continue;
            }
            $out = array_merge($out, $this->collectPhpFilesExcludingTests($sub));
        }
        return $out;
    }
}
