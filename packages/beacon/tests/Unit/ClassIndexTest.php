<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Unit;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Switon\Beacon\ClassIndex;
use Switon\Beacon\Exception\ClassIndexNotFoundException;
use Switon\Core\FilesystemInterface;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function realpath;
use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;

#[AllowMockObjectsWithoutExpectations]
class ClassIndexTest extends TestCase
{
    public function testCollectPhpFilesExcludingTestsFiltersTestsAndRecurses(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->method('files')->willReturnCallback(static function (string $dir): array {
            return match ($dir) {
                '@app' => ['@app/Main.php', '@app/README.md'],
                '@app/Feature' => ['@app/Feature/Service.php', '@app/Feature/tests/Ignored.php'],
                '@app/Feature/Nested' => ['@app/Feature/Nested/Handler.php'],
                default => [],
            };
        });
        $filesystem->method('directories')->willReturnCallback(static function (string $dir): array {
            return match ($dir) {
                '@app' => ['@app/tests', '@app/Feature'],
                '@app/Feature' => ['@app/Feature/Nested'],
                default => [],
            };
        });

        $index = new ExposedClassIndex($filesystem);
        $files = $index->collectPhpFilesExcludingTestsPublic('@app');

        $this->assertSame(
            ['@app/Main.php', '@app/Feature/Service.php', '@app/Feature/Nested/Handler.php'],
            $files
        );
    }

    public function testEnsureClassmapFreshThrowsWhenClassmapMissing(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->method('exists')->willReturnCallback(static fn (string $path): bool => $path !== '@vendor/composer/autoload_classmap.php');

        $index = new ExposedClassIndex($filesystem);

        $this->expectException(ClassIndexNotFoundException::class);
        $this->expectExceptionMessage('composer dump-autoload -o');

        $index->ensureClassmapFreshPublic();
    }

    public function testEnsureClassmapFreshThrowsWhenClassmapIsOlderThanLock(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->method('exists')->willReturnCallback(static fn (string $path): bool => $path === '@vendor/composer/autoload_classmap.php' || $path === '@root/composer.lock');
        $filesystem->method('mtime')->willReturnCallback(static function (string $path): ?int {
            return match ($path) {
                '@vendor/composer/autoload_classmap.php' => 100,
                '@root/composer.lock' => 200,
                default => null,
            };
        });

        $index = new ExposedClassIndex($filesystem);

        $this->expectException(ClassIndexNotFoundException::class);
        $this->expectExceptionMessage('composer dump-autoload -o');

        $index->ensureClassmapFreshPublic();
    }

    public function testEnsureClassmapFreshPassesWhenLockFileMissing(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->method('exists')->willReturnCallback(static fn (string $path): bool => $path === '@vendor/composer/autoload_classmap.php');
        $filesystem->method('mtime')->willReturn(100);

        $index = new ExposedClassIndex($filesystem);
        $index->ensureClassmapFreshPublic();

        $this->addToAssertionCount(1);
    }

    public function testEnsureClassmapFreshIgnoresLockComparisonWhenClassmapMtimeIsNull(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->method('exists')->willReturnCallback(
            static fn (string $path): bool => $path === '@vendor/composer/autoload_classmap.php' || $path === '@root/composer.lock'
        );
        $filesystem->method('mtime')->willReturnCallback(static function (string $path): ?int {
            return match ($path) {
                '@vendor/composer/autoload_classmap.php' => null,
                '@root/composer.lock' => 999,
                default => null,
            };
        });

        $index = new ExposedClassIndex($filesystem);
        $index->ensureClassmapFreshPublic();

        $this->addToAssertionCount(1);
    }

    public function testCollectFqcnsFromFilesKeepsOnlyNamespaceAndMatchingBasename(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'beacon-classindex-' . $suffix;
        mkdir($dir, 0777, true);

        $goodClass = 'Tmp\\Beacon\\Good' . $suffix;
        $badNameClass = 'Tmp\\Beacon\\Different' . $suffix;
        $globalClass = 'Global' . $suffix;

        $goodShort = $this->shortName($goodClass);
        $badShort = $this->shortName($badNameClass);

        $goodFile = $dir . DIRECTORY_SEPARATOR . $goodShort . '.php';
        $badNameFile = $dir . DIRECTORY_SEPARATOR . 'OtherName.php';
        $globalFile = $dir . DIRECTORY_SEPARATOR . $globalClass . '.php';

        file_put_contents($goodFile, "<?php\nnamespace Tmp\\Beacon;\nclass {$goodShort} {}\n");
        file_put_contents($badNameFile, "<?php\nnamespace Tmp\\Beacon;\nclass {$badShort} {}\n");
        file_put_contents($globalFile, "<?php\nclass {$globalClass} {}\n");

        $filesystem = $this->createMock(FilesystemInterface::class);
        $index = new ExposedClassIndex($filesystem);

        $result = $index->collectFqcnsFromFilesPublic([
            (string)realpath($goodFile),
            (string)realpath($badNameFile),
            (string)realpath($globalFile),
        ]);

        $this->assertSame([$goodClass], $result);
    }

    public function testCollectFqcnsFromFilesReturnsEmptyForNoFiles(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $index = new ExposedClassIndex($filesystem);

        $this->assertSame([], $index->collectFqcnsFromFilesPublic([]));
    }

    public function testEnsureClassmapFreshPassesWhenClassmapNewerThanLock(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->method('exists')->willReturnCallback(static fn (string $path): bool => $path === '@vendor/composer/autoload_classmap.php' || $path === '@root/composer.lock');
        $filesystem->method('mtime')->willReturnCallback(static function (string $path): ?int {
            return match ($path) {
                '@vendor/composer/autoload_classmap.php' => 300,
                '@root/composer.lock' => 200,
                default => null,
            };
        });

        $index = new ExposedClassIndex($filesystem);
        $index->ensureClassmapFreshPublic();

        $this->addToAssertionCount(1);
    }

    public function testAllThrowsWhenVendorClassCountBelowTwenty(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->method('exists')->willReturnCallback(static fn (string $path): bool => $path === '@vendor/composer/autoload_classmap.php');
        $filesystem->method('mtime')->willReturn(100);

        $index = new ExposedClassIndex($filesystem);
        $index->appClassesOverride = [];
        $index->vendorClassesOverride = $this->vendorFqcnList(19);

        $this->expectException(ClassIndexNotFoundException::class);
        $this->expectExceptionMessage('composer dump-autoload -o');

        $index->all();
    }

    public function testAllMergesAppAndVendorAndCachesOnSecondCall(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->method('exists')->willReturnCallback(static fn (string $path): bool => $path === '@vendor/composer/autoload_classmap.php');
        $filesystem->method('mtime')->willReturn(100);

        $index = new ExposedClassIndex($filesystem);
        $index->appClassesOverride = ['App\\BeaconSmoke'];
        $index->vendorClassesOverride = $this->vendorFqcnList(20);

        $first = $index->all();
        $second = $index->all();

        $this->assertCount(21, $first);
        $this->assertSame('App\\BeaconSmoke', $first[0]);
        $this->assertSame($first, $second);
        $this->assertSame(1, $index->buildFromAppCallCount);
        $this->assertSame(1, $index->getVendorClassesCallCount);
    }

    public function testGetVendorClassCountCachesAfterFirstComposerFetch(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->method('exists')->willReturnCallback(static fn (string $path): bool => $path === '@vendor/composer/autoload_classmap.php');
        $filesystem->method('mtime')->willReturn(100);

        $index = new ExposedClassIndex($filesystem);
        $index->vendorClassesOverride = $this->vendorFqcnList(22);

        $this->assertSame(22, $index->getVendorClassCount());
        $this->assertSame(22, $index->getVendorClassCount());
        $this->assertSame(1, $index->getVendorClassesCallCount);
    }

    public function testGetDevPackagePathsReturnsListShape(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $index = new ExposedClassIndex($filesystem);
        $paths = $index->getDevPackagePathsPublic();

        $this->assertIsArray($paths);
        foreach ($paths as $p) {
            $this->assertIsString($p);
            $this->assertStringEndsWith('/', $p);
        }
    }

    public function testGetVendorClassesFromComposerSkipsTestsAndDevPaths(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'beacon-classindex-vendor-' . uniqid('', true);
        mkdir($root . '/vendor/acme/pkg/src', 0777, true);
        mkdir($root . '/vendor/acme/pkg/tests', 0777, true);
        mkdir($root . '/vendor/acme/devpkg/src', 0777, true);
        file_put_contents($root . '/vendor/acme/pkg/src/LiveClass.php', "<?php\nnamespace Acme\\Pkg;\nfinal class LiveClass {}\n");
        file_put_contents($root . '/vendor/acme/pkg/tests/TestClass.php', "<?php\nnamespace Acme\\Pkg;\nfinal class TestClass {}\n");
        file_put_contents($root . '/vendor/acme/devpkg/src/DevClass.php', "<?php\nnamespace Acme\\DevPkg;\nfinal class DevClass {}\n");

        $loader = new ClassLoader($root . '/vendor');
        $loader->addClassMap([
            'Acme\\Pkg\\LiveClass' => $root . '/vendor/acme/pkg/src/LiveClass.php',
            'Acme\\Pkg\\TestClass' => $root . '/vendor/acme/pkg/tests/TestClass.php',
            'Acme\\DevPkg\\DevClass' => $root . '/vendor/acme/devpkg/src/DevClass.php',
        ]);
        $loader->register(true);

        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->method('exists')->willReturnCallback(static fn (string $path): bool => $path === '@vendor/composer/autoload_classmap.php');
        $filesystem->method('mtime')->willReturn(100);

        $index = new ExposedClassIndex($filesystem);
        $index->devPackagePathsOverride = [str_replace('\\', '/', $root . '/vendor/acme/devpkg/')];

        try {
            $classes = $index->getVendorClassesFromComposerPublic();

            $this->assertContains('Acme\\Pkg\\LiveClass', $classes);
            $this->assertNotContains('Acme\\Pkg\\TestClass', $classes);
            $this->assertNotContains('Acme\\DevPkg\\DevClass', $classes);
        } finally {
            $loader->unregister();
        }
    }

    private function vendorFqcnList(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = 'Vendor\\BeaconIdx\\C' . $i;
        }

        return $out;
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return (string)array_last($parts);
    }
}

class ExposedClassIndex extends ClassIndex
{
    /** @var list<string>|null When non-null, {@see getVendorClassesFromComposer} returns this (for tests). */
    public ?array $vendorClassesOverride = null;

    /** @var list<string>|null When non-null, {@see buildFromApp} returns this (for tests). */
    public ?array $appClassesOverride = null;

    /** @var list<string>|null */
    public ?array $devPackagePathsOverride = null;

    public int $buildFromAppCallCount = 0;

    public int $getVendorClassesCallCount = 0;

    public function collectPhpFilesExcludingTestsPublic(string $dir): array
    {
        return $this->collectPhpFilesExcludingTests($dir);
    }

    public function ensureClassmapFreshPublic(): void
    {
        $this->ensureClassmapFresh();
    }

    public function collectFqcnsFromFilesPublic(array $files): array
    {
        return $this->collectFqcnsFromFiles($files);
    }

    /** @return list<string> */
    public function getDevPackagePathsPublic(): array
    {
        return $this->getDevPackagePaths();
    }

    public function getVendorClassesFromComposerPublic(): array
    {
        return parent::getVendorClassesFromComposer();
    }

    protected function buildFromApp(): array
    {
        $this->buildFromAppCallCount++;
        if ($this->appClassesOverride !== null) {
            return $this->appClassesOverride;
        }

        return parent::buildFromApp();
    }

    protected function getVendorClassesFromComposer(): array
    {
        $this->getVendorClassesCallCount++;
        if ($this->vendorClassesOverride !== null) {
            return $this->vendorClassesOverride;
        }

        return parent::getVendorClassesFromComposer();
    }

    protected function getDevPackagePaths(): array
    {
        if ($this->devPackagePathsOverride !== null) {
            return $this->devPackagePathsOverride;
        }

        return parent::getDevPackagePaths();
    }
}
