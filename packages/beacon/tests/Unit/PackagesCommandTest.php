<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Switon\Beacon\Command\PackagesCommand;
use Switon\Core\ConsoleInterface;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Testing\Container;
use RuntimeException;

use function json_decode;

#[AllowMockObjectsWithoutExpectations]
class PackagesCommandTest extends TestCase
{
    protected ConsoleInterface&MockObject $console;

    protected function setUp(): void
    {
        parent::setUp();
        $this->console = $this->createMock(ConsoleInterface::class);
    }

    private function makeTestableCommand(): TestablePackagesCommand
    {
        $container = new Container([
            ConsoleInterface::class => $this->console,
        ]);

        return $container->make(TestablePackagesCommand::class);
    }

    private function makeRealCommand(FilesystemInterface $filesystem, PathAliasInterface $pathAlias): RealPackagesCommand
    {
        $container = new Container([
            ConsoleInterface::class => $this->console,
            FilesystemInterface::class => $filesystem,
            PathAliasInterface::class => $pathAlias,
        ]);

        return $container->make(RealPackagesCommand::class);
    }

    public function testOfficialActionReturnsPackagesWithClassesByDefault(): void
    {
        $command = $this->makeTestableCommand();
        $command->stubbedPackages = [
            [
                'install' => 'switon/http',
                'namespace' => 'Switon\\Http',
                'keywords' => 'http filter',
                'classes' => 'Kernel RequestHandler',
            ],
        ];

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $command->officialAction();

        $this->assertSame(0, $code);
        $this->assertSame('/virtual/resources/data/official-packages.json', $command->capturedReadPath);
        $this->assertSame('Kernel RequestHandler', $captured['packages'][0]['classes']);
    }

    public function testOfficialActionRemovesClassesWhenDisabled(): void
    {
        $command = $this->makeTestableCommand();
        $command->stubbedPackages = [
            [
                'install' => 'switon/http',
                'namespace' => 'Switon\\Http',
                'keywords' => 'http filter',
                'classes' => 'Kernel RequestHandler',
            ],
        ];

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $command->officialAction(false);

        $this->assertSame(0, $code);
        $this->assertArrayNotHasKey('classes', $captured['packages'][0]);
    }

    public function testOfficialActionReturnsOneWhenReadPackagesFails(): void
    {
        $command = $this->makeTestableCommand();
        $command->stubbedPackages = null;
        $command->filesystemExists = false;

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $command->officialAction();

        $this->assertSame(1, $code);
        $this->assertSame('data file not found', $captured['error']);
    }

    public function testSyncClassesActionScansVendorPackagesRootAndWritesClasses(): void
    {
        $tempRoot = sys_get_temp_dir() . '/beacon-packages-' . uniqid('', true);
        $vendorRoot = $tempRoot . '/switon';
        $srcDir = $vendorRoot . '/demo/src/Sub';

        mkdir($srcDir, 0777, true);
        file_put_contents($vendorRoot . '/demo/src/RootClass.php', '<?php');
        file_put_contents($vendorRoot . '/demo/src/RootInterface.php', '<?php');
        file_put_contents($srcDir . '/LeafInterface.php', '<?php');
        file_put_contents($srcDir . '/lower.php', '<?php');

        $command = $this->makeTestableCommand();
        $command->packagesVendorRootOverride = $vendorRoot;
        $command->stubbedPackages = [
            [
                'install' => 'switon/demo',
                'namespace' => 'Switon\\Demo',
                'keywords' => 'demo package',
            ],
        ];

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $command->syncClassesAction();

        $this->assertSame(0, $code);
        $this->assertSame('/virtual/resources/data/official-packages.json', $command->capturedWritePath);
        $this->assertSame('ok', $captured['status']);
        $this->assertSame(
            'Root RootClass Sub/Leaf',
            $command->writtenPackages[0]['classes']
        );

        $this->removeDirectory($tempRoot);
    }

    public function testSyncClassesActionReturnsErrorWhenWriteFails(): void
    {
        $command = $this->makeTestableCommand();
        $command->writePackagesReturn = false;
        $command->stubbedPackages = [
            ['install' => 'switon/demo', 'namespace' => 'Switon\\Demo', 'keywords' => 'demo'],
        ];

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $command->syncClassesAction();

        $this->assertSame(1, $code);
        $this->assertSame('failed to write data', $captured['error']);
    }

    public function testSyncClassesActionClearsClassesForNonSwitonInstall(): void
    {
        $tempRoot = sys_get_temp_dir() . '/beacon-packages-' . uniqid('', true);
        $vendorRoot = $tempRoot . '/switon';
        mkdir($vendorRoot . '/other/src', 0777, true);
        file_put_contents($vendorRoot . '/other/src/X.php', '<?php');

        $command = $this->makeTestableCommand();
        $command->packagesVendorRootOverride = $vendorRoot;
        $command->stubbedPackages = [
            [
                'install' => 'vendor/other',
                'namespace' => 'Other\\Ns',
                'keywords' => 'other',
                'classes' => 'should be removed',
            ],
        ];

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $command->syncClassesAction();

        $this->assertSame(0, $code);
        $this->assertSame('ok', $captured['status']);
        $this->assertArrayNotHasKey('classes', $command->writtenPackages[0]);

        $this->removeDirectory($tempRoot);
    }

    public function testSyncClassesActionClearsClassesForEmDashNamespace(): void
    {
        $tempRoot = sys_get_temp_dir() . '/beacon-packages-' . uniqid('', true);
        $vendorRoot = $tempRoot . '/switon';
        mkdir($vendorRoot . '/demo/src', 0777, true);
        file_put_contents($vendorRoot . '/demo/src/A.php', '<?php');

        $command = $this->makeTestableCommand();
        $command->packagesVendorRootOverride = $vendorRoot;
        $command->stubbedPackages = [
            [
                'install' => 'switon/demo',
                'namespace' => '—',
                'keywords' => 'placeholder',
                'classes' => 'old',
            ],
        ];

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $command->syncClassesAction();

        $this->assertSame(0, $code);
        $this->assertArrayNotHasKey('classes', $command->writtenPackages[0]);

        $this->removeDirectory($tempRoot);
    }

    public function testSyncClassesActionClearsClassesWhenSrcMissing(): void
    {
        $tempRoot = sys_get_temp_dir() . '/beacon-packages-' . uniqid('', true);
        $vendorRoot = $tempRoot . '/switon';
        mkdir($vendorRoot . '/demo', 0777, true);

        $command = $this->makeTestableCommand();
        $command->packagesVendorRootOverride = $vendorRoot;
        $command->stubbedPackages = [
            ['install' => 'switon/demo', 'namespace' => 'Switon\\Demo', 'keywords' => 'demo', 'classes' => 'x'],
        ];

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $command->syncClassesAction();

        $this->assertSame(0, $code);
        $this->assertArrayNotHasKey('classes', $command->writtenPackages[0]);

        $this->removeDirectory($tempRoot);
    }

    public function testSyncClassesActionSetsEmptyStringWhenNoValidClassFiles(): void
    {
        $tempRoot = sys_get_temp_dir() . '/beacon-packages-' . uniqid('', true);
        $vendorRoot = $tempRoot . '/switon';
        mkdir($vendorRoot . '/demo/src', 0777, true);
        file_put_contents($vendorRoot . '/demo/src/bad-name.php', '<?php');

        $command = $this->makeTestableCommand();
        $command->packagesVendorRootOverride = $vendorRoot;
        $command->stubbedPackages = [
            ['install' => 'switon/demo', 'namespace' => 'Switon\\Demo', 'keywords' => 'demo'],
        ];

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $command->syncClassesAction();

        $this->assertSame(0, $code);
        $this->assertSame('', $command->writtenPackages[0]['classes']);

        $this->removeDirectory($tempRoot);
    }

    public function testSyncClassesActionSkipsNonArrayPackage(): void
    {
        $command = $this->makeTestableCommand();
        $command->stubbedPackages = [
            'not-an-array',
            ['install' => 'switon/x', 'namespace' => 'Switon\\X', 'keywords' => 'x'],
        ];

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $command->syncClassesAction();

        $this->assertSame(0, $code);
        $this->assertCount(2, $command->writtenPackages);
        $this->assertSame('not-an-array', $command->writtenPackages[0]);
    }

    public function testCollectClassPathsStripsInterfaceSuffixAndSkipsInvalidBasenames(): void
    {
        $src = sys_get_temp_dir() . '/beacon-collect-' . uniqid('', true);
        mkdir($src . '/Nested', 0777, true);
        file_put_contents($src . '/ValidClass.php', '<?php');
        file_put_contents($src . '/Nested/PortInterface.php', '<?php');
        file_put_contents($src . '/bad-dash.php', '<?php');
        file_put_contents($src . '/Interface.php', '<?php');

        $command = $this->makeTestableCommand();
        $paths = $command->exposedCollectClassPaths($src);
        sort($paths, SORT_STRING);

        $this->assertSame(['Nested/Port', 'ValidClass'], $paths);

        $this->removeDirectory($src);
    }

    public function testReadPackagesReturnsNullWhenDataFileMissing(): void
    {
        $command = $this->makeTestableCommand();
        $command->stubbedPackages = null;
        $command->filesystemExists = false;

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $this->assertSame(null, $command->exposedReadPackages('/virtual/missing.json'));
        $this->assertSame('data file not found', $captured['error']);
    }

    public function testReadPackagesReturnsNullWhenReadThrows(): void
    {
        $command = $this->makeTestableCommand();
        $command->stubbedPackages = null;
        $command->filesystemReadThrows = true;

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $this->assertSame(null, $command->exposedReadPackages('/virtual/data.json'));
        $this->assertSame('failed to read data', $captured['error']);
    }

    public function testReadPackagesReturnsNullWhenJsonIsInvalidOrNotArray(): void
    {
        $command = $this->makeTestableCommand();
        $command->stubbedPackages = null;
        $command->stubbedReadJson = '{';

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $this->assertSame(null, $command->exposedReadPackages('/virtual/data.json'));
        $this->assertSame('invalid data', $captured['error']);
    }

    public function testWritePackagesReturnsFalseWhenFilesystemWriteThrows(): void
    {
        $command = $this->makeTestableCommand();
        $command->filesystemWriteThrows = true;

        $this->assertFalse($command->exposedWritePackages('/virtual/data.json', '[]'));
    }

    public function testPathHelpersReturnResolvedAliases(): void
    {
        $command = $this->makeTestableCommand();

        $this->assertSame('/virtual/resources/data/official-packages.json', $command->exposedOfficialPackagesPath());
        $this->assertSame('/virtual/vendor/switon', $command->exposedPackagesVendorRoot());
    }

    public function testOfficialActionUsesRealDependenciesAndKeepsClasses(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createMock(PathAliasInterface::class);
        $pathAlias->expects($this->once())
            ->method('resolve')
            ->with('@switon.beacon.resources/data/official-packages.json')
            ->willReturn('/virtual/resources/data/official-packages.json');
        $filesystem->expects($this->once())
            ->method('exists')
            ->with('/virtual/resources/data/official-packages.json')
            ->willReturn(true);
        $filesystem->expects($this->once())
            ->method('read')
            ->with('/virtual/resources/data/official-packages.json')
            ->willReturn('[{"install":"switon/http","namespace":"Switon\\\\Http","keywords":"http filter","classes":"Kernel RequestHandler"}]');
        $command = $this->makeRealCommand($filesystem, $pathAlias);

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $command->officialAction();

        $this->assertSame(0, $code);
        $this->assertSame('Kernel RequestHandler', $captured['packages'][0]['classes']);
    }

    public function testOfficialActionReturnsOneWhenRealReadPackagesMissing(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createMock(PathAliasInterface::class);
        $pathAlias->expects($this->once())
            ->method('resolve')
            ->with('@switon.beacon.resources/data/official-packages.json')
            ->willReturn('/virtual/resources/data/official-packages.json');
        $filesystem->expects($this->once())
            ->method('exists')
            ->with('/virtual/resources/data/official-packages.json')
            ->willReturn(false);
        $command = $this->makeRealCommand($filesystem, $pathAlias);

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $command->officialAction();

        $this->assertSame(1, $code);
        $this->assertSame('data file not found', $captured['error']);
    }

    public function testReadPackagesReturnsNullWhenRealFilesystemReadThrows(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->expects($this->once())
            ->method('exists')
            ->with('/virtual/data.json')
            ->willReturn(true);
        $filesystem->expects($this->once())
            ->method('read')
            ->with('/virtual/data.json')
            ->willThrowException(new RuntimeException('boom'));
        $pathAlias = $this->createMock(PathAliasInterface::class);
        $pathAlias->method('resolve')->willReturn('/virtual/path');
        $command = $this->makeRealCommand($filesystem, $pathAlias);

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $this->assertSame(null, $command->readPackagesPublic('/virtual/data.json'));
        $this->assertSame('failed to read data', $captured['error']);
    }

    public function testReadPackagesReturnsNullWhenRealJsonIsInvalid(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->expects($this->once())
            ->method('exists')
            ->with('/virtual/data.json')
            ->willReturn(true);
        $filesystem->expects($this->once())
            ->method('read')
            ->with('/virtual/data.json')
            ->willReturn('{');
        $pathAlias = $this->createMock(PathAliasInterface::class);
        $pathAlias->method('resolve')->willReturn('/virtual/path');
        $command = $this->makeRealCommand($filesystem, $pathAlias);

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $this->assertSame(null, $command->readPackagesPublic('/virtual/data.json'));
        $this->assertSame('invalid data', $captured['error']);
    }

    public function testWritePackagesReturnsFalseWhenRealFilesystemWriteThrows(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->expects($this->once())
            ->method('write')
            ->with('/virtual/data.json', '[]')
            ->willThrowException(new RuntimeException('boom'));
        $pathAlias = $this->createMock(PathAliasInterface::class);
        $pathAlias->method('resolve')->willReturn('/virtual/path');
        $command = $this->makeRealCommand($filesystem, $pathAlias);

        $this->assertFalse($command->writePackagesPublic('/virtual/data.json', '[]'));
    }

    public function testSyncClassesActionUsesRealDependenciesAndWritesClasses(): void
    {
        $tempRoot = sys_get_temp_dir() . '/beacon-packages-' . uniqid('', true);
        $vendorRoot = $tempRoot . '/switon';
        $srcDir = $vendorRoot . '/demo/src/Sub';

        mkdir($srcDir, 0777, true);
        file_put_contents($vendorRoot . '/demo/src/RootClass.php', '<?php');
        file_put_contents($vendorRoot . '/demo/src/RootInterface.php', '<?php');
        file_put_contents($srcDir . '/LeafInterface.php', '<?php');
        file_put_contents($srcDir . '/bad-name.php', '<?php');

        $filesystem = $this->createMock(FilesystemInterface::class);
        $pathAlias = $this->createMock(PathAliasInterface::class);
        $pathAlias->expects($this->exactly(2))
            ->method('resolve')
            ->willReturnCallback(static function (string $path) use ($vendorRoot): string {
                return match ($path) {
                    '@switon.beacon.resources/data/official-packages.json' => '/virtual/resources/data/official-packages.json',
                    '@vendor/switon' => $vendorRoot,
                    default => throw new RuntimeException('unexpected alias ' . $path),
                };
            });
        $filesystem->expects($this->once())
            ->method('exists')
            ->with('/virtual/resources/data/official-packages.json')
            ->willReturn(true);
        $filesystem->expects($this->once())
            ->method('read')
            ->with('/virtual/resources/data/official-packages.json')
            ->willReturn('[{"install":"switon/demo","namespace":"Switon\\\\Demo","keywords":"demo package"}]');
        $writtenJson = null;
        $filesystem->expects($this->once())
            ->method('write')
            ->with('/virtual/resources/data/official-packages.json', $this->callback(static function (string $json) use (&$writtenJson): bool {
                $writtenJson = $json;
                return true;
            }));
        $command = $this->makeRealCommand($filesystem, $pathAlias);

        $captured = null;
        $this->console->expects($this->once())
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $command->syncClassesAction();

        $this->assertSame(0, $code);
        $this->assertSame('ok', $captured['status']);
        $this->assertNotNull($writtenJson);
        $written = json_decode($writtenJson, true);
        $this->assertSame('Root RootClass Sub/Leaf', $written[0]['classes']);

        $this->removeDirectory($tempRoot);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->removeDirectory($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}

class TestablePackagesCommand extends PackagesCommand
{
    /** @var array<int, array<string, mixed>>|null */
    public ?array $stubbedPackages = [];
    public ?array $writtenPackages = null;
    public ?string $capturedReadPath = null;
    public ?string $capturedWritePath = null;
    public string $officialPackagesPathOverride = '/virtual/resources/data/official-packages.json';
    public string $packagesVendorRootOverride = '/virtual/vendor/switon';
    public bool $writePackagesReturn = true;
    public bool $filesystemExists = true;
    public bool $filesystemReadThrows = false;
    public ?string $stubbedReadJson = '[]';
    public bool $filesystemWriteThrows = false;

    /** @return array<int, string> */
    public function exposedCollectClassPaths(string $srcDir): array
    {
        return $this->collectClassPaths($srcDir);
    }

    public function exposedReadPackages(string $path): ?array
    {
        return $this->readPackages($path);
    }

    public function exposedWritePackages(string $path, string $json): bool
    {
        return $this->writePackages($path, $json);
    }

    public function exposedOfficialPackagesPath(): string
    {
        return $this->officialPackagesPath();
    }

    public function exposedPackagesVendorRoot(): string
    {
        return $this->packagesVendorRoot();
    }

    protected function readPackages(string $path): ?array
    {
        $this->capturedReadPath = $path;

        if ($this->stubbedPackages !== null) {
            return $this->stubbedPackages;
        }

        if (!$this->filesystemExists) {
            $this->console->writeLn('{"error":"data file not found"}');
            return null;
        }

        if ($this->filesystemReadThrows) {
            $this->console->writeLn('{"error":"failed to read data"}');
            return null;
        }

        $json = $this->stubbedReadJson ?? '[]';
        $packages = json_decode($json, true);

        if (!is_array($packages)) {
            $this->console->writeLn('{"error":"invalid data"}');
            return null;
        }

        return $packages;
    }

    protected function writePackages(string $path, string $json): bool
    {
        $this->capturedWritePath = $path;
        if ($this->filesystemWriteThrows) {
            return false;
        }
        if (!$this->writePackagesReturn) {
            return false;
        }
        $data = json_decode($json, true);
        $this->writtenPackages = is_array($data) ? $data : null;

        return true;
    }

    protected function officialPackagesPath(): string
    {
        return $this->officialPackagesPathOverride;
    }

    protected function packagesVendorRoot(): string
    {
        return $this->packagesVendorRootOverride;
    }
}

class RealPackagesCommand extends PackagesCommand
{
    public function readPackagesPublic(string $path): ?array
    {
        return $this->readPackages($path);
    }

    public function writePackagesPublic(string $path, string $json): bool
    {
        return $this->writePackages($path, $json);
    }
}
