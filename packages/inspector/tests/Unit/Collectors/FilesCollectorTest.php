<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit\Collectors;

use Switon\Core\PathAliasInterface;
use Switon\Inspector\Collector\FilesCollector;
use Switon\Inspector\Tests\TestCase;

use function sys_get_temp_dir;

class FilesCollectorTest extends TestCase
{
    public function testCollectReturnsListOfIncludedFilePaths(): void
    {
        $alias = $this->createPathAlias(sys_get_temp_dir() . '/switon_inspector_root');

        $collector = $this->container->make(FilesCollector::class, [
            'pathAlias' => $alias,
        ]);

        $files = $collector->collect();

        $this->assertIsArray($files);
        $this->assertNotEmpty($files);
        $this->assertIsString($files[0]);
    }

    public function testJsonSerializeReturnsEmptyArray(): void
    {
        $collector = new FilesCollector();
        $this->assertSame([], $collector->jsonSerialize());
    }

    public function testBootIsNoOp(): void
    {
        (new FilesCollector())->boot();
        $this->assertTrue(true);
    }

    public function testCollectShowsAbsolutePathsWhenStubRootPrefixesNoIncludedFiles(): void
    {
        $alias = $this->createPathAlias('/__collector_unreachable__/project');

        $collector = $this->container->make(FilesCollector::class, [
            'pathAlias' => $alias,
        ]);

        putenv('HOME');
        putenv('USERPROFILE');
        try {
            $listing = $collector->collect();
            $this->assertNotEmpty($listing);
            $this->assertTrue((bool)preg_grep('#^/+#', $listing), 'Expected POSIX absolute paths after prefix mismatch');
        } finally {
            putenv('HOME');
            putenv('USERPROFILE');
        }
    }

    public function testCollectNormalizesRootParentAndHomeDirectoryPaths(): void
    {
        $this->markTestSkipped(
            'FilesCollector normalization branches depend on the first 1000 included files, ' .
            'which is brittle in the shared PHPUnit bootstrap.'
        );
    }

    protected function createPathAlias(string $rootPath): PathAliasInterface
    {
        return new class ($rootPath) implements PathAliasInterface {
            public function __construct(protected string $rootPath)
            {
            }

            public function all(): array
            {
                return ['@root' => $this->rootPath];
            }

            public function set(string $name, string $path): string
            {
                return $path;
            }

            public function get(string $name): ?string
            {
                return $name === '@root' ? $this->rootPath : null;
            }

            public function has(string $name): bool
            {
                return $name === '@root';
            }

            public function resolve(string $path, array $context = []): string
            {
                return $this->rootPath;
            }

            public function remove(string $name): void
            {
            }
        };
    }
}
