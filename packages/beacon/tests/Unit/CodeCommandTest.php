<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Switon\Beacon\Command\CodeCommand;
use Switon\Core\ConsoleInterface;
use Switon\Testing\Container;
use ReflectionMethod;

use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function mkdir;
use function sleep;
use function sys_get_temp_dir;
use function uniqid;

#[AllowMockObjectsWithoutExpectations]
class CodeCommandTest extends TestCase
{
    protected CodeCommand $command;
    protected ConsoleInterface&MockObject $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->console = $this->createMock(ConsoleInterface::class);
        $this->command = $this->makeCommand();
    }

    public function testLintActionReturnsOkWhenDirectoryDoesNotExist(): void
    {
        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->lintAction('not-exist-' . uniqid());

        $this->assertSame(0, $code);
        $this->assertTrue($captured['ok']);
        $this->assertSame([], $captured['errors']);
    }

    public function testLintActionSkipsNonPhpFiles(): void
    {
        $dir = sys_get_temp_dir() . '/beacon-lint-' . uniqid();
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/README.md', '# not php');

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->lintAction($dir);

        $this->assertSame(0, $code);
        $this->assertTrue($captured['ok']);
        $this->assertSame([], $captured['errors']);
    }

    public function testLintActionReportsSyntaxErrors(): void
    {
        $dir = sys_get_temp_dir() . '/beacon-lint-' . uniqid();
        mkdir($dir, 0777, true);
        $badFile = $dir . '/Bad.php';
        file_put_contents($badFile, "<?php\nfunction broken( {\n");

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->lintAction($dir);

        $this->assertSame(1, $code);
        $this->assertFalse($captured['ok']);
        $this->assertCount(1, $captured['errors']);
        $this->assertSame($badFile, $captured['errors'][0]['file']);
    }

    public function testResolvePathUsesAbsoluteRelativeAndDefault(): void
    {
        $this->assertSame('/tmp/abc', $this->command->callResolvePath('/tmp/abc'));
        $this->assertStringEndsWith('/foo/bar', $this->command->callResolvePath('foo/bar'));
        $this->assertStringEndsWith('/app', $this->command->callResolvePath(''));
    }

    public function testCollectPhpFilesReturnsOnlyPhpRecursively(): void
    {
        $dir = sys_get_temp_dir() . '/beacon-collect-' . uniqid();
        mkdir($dir . '/nested', 0777, true);
        file_put_contents($dir . '/A.php', '<?php echo 1;');
        file_put_contents($dir . '/nested/B.php', '<?php echo 2;');
        file_put_contents($dir . '/nested/C.txt', 'nope');

        $files = $this->command->callCollectPhpFiles($dir);
        sort($files);

        $this->assertCount(2, $files);
        $this->assertSame([$dir . '/A.php', $dir . '/nested/B.php'], $files);
    }

    public function testVisibilityMapsMethodModifiers(): void
    {
        $this->assertSame('public', $this->command->callVisibility(ReflectionMethod::IS_PUBLIC));
        $this->assertSame('protected', $this->command->callVisibility(ReflectionMethod::IS_PROTECTED));
        $this->assertSame('private', $this->command->callVisibility(ReflectionMethod::IS_PRIVATE));
        $this->assertSame('public', $this->command->callVisibility(0));
    }

    public function testExtractSummaryFromDocblockReturnsFirstMeaningfulLine(): void
    {
        $doc = "/**\n *\n *  My summary line\n * @return void\n */";
        $this->assertSame('My summary line', $this->command->callExtractSummaryFromDocblock($doc));
        $this->assertSame('', $this->command->callExtractSummaryFromDocblock('/** */'));
    }

    public function testExtractClassSummariesFromSourceParsesClassEnumAndMethods(): void
    {
        $code = <<<'PHP'
<?php
namespace Demo\App;

/**
 * Main class summary
 */
class SampleClass {
    /**
     * Method summary
     */
    public function run(): void {}
}

/**
 * Enum summary
 */
enum Status {
    /** Case helper */
    public function label(): string { return 'ok'; }
}
PHP;
        $items = $this->command->callExtractClassSummariesFromSource($code, '/tmp/Sample.php');

        $this->assertCount(2, $items);
        $this->assertSame('SampleClass', $items[0]['class']);
        $this->assertSame('Main class summary', $items[0]['summary']);
        $this->assertSame('run', $items[0]['methods'][0]['name']);
        $this->assertSame('', $items[0]['methods'][0]['summary']);
        $this->assertSame('Status', $items[1]['class']);
        $this->assertSame('Enum summary', $items[1]['summary']);
    }

    public function testGetVendorDevPackageNamesReturnsConfiguredNames(): void
    {
        $dir = sys_get_temp_dir() . '/beacon-vendor-' . uniqid();
        mkdir($dir . '/vendor/composer', 0777, true);
        file_put_contents($dir . '/vendor/composer/installed.json', '{"dev-package-names":["a/b","c/d"]}');

        $names = $this->command->callGetVendorDevPackageNames($dir);
        $this->assertSame(['a/b', 'c/d'], $names);
    }

    public function testGetVendorDevPackageNamesReturnsEmptyWhenInstalledJsonMissing(): void
    {
        $dir = sys_get_temp_dir() . '/beacon-vendor-empty-' . uniqid();
        mkdir($dir, 0777, true);

        $this->assertSame([], $this->command->callGetVendorDevPackageNames($dir));
    }

    public function testGetVendorCachePathAppendsVendorRelativeFile(): void
    {
        $root = sys_get_temp_dir() . '/beacon-code-root-' . uniqid();
        $path = $this->command->callGetVendorCachePath($root);

        $this->assertStringEndsWith('/vendor/switon/cli-code-index.json', str_replace('\\', '/', $path));
    }

    public function testIsVendorCacheValidFalseWhenCacheMissing(): void
    {
        $root = sys_get_temp_dir() . '/beacon-cache-' . uniqid();
        mkdir($root, 0777, true);

        $this->assertFalse($this->command->callIsVendorCacheValid($root));
    }

    public function testIsVendorCacheValidFalseWhenLockNewerThanCache(): void
    {
        $root = sys_get_temp_dir() . '/beacon-cache-' . uniqid();
        mkdir($root . '/vendor/composer', 0777, true);
        mkdir($root . '/vendor/switon', 0777, true);
        $cache = $root . '/vendor/switon/cli-code-index.json';
        $lock = $root . '/vendor/composer/installed.json';
        file_put_contents($cache, '{}');
        sleep(1);
        file_put_contents($lock, '{}');

        $this->assertFalse($this->command->callIsVendorCacheValid($root));
    }

    public function testIsVendorCacheValidTrueWhenCacheExistsAndNoInstalledJson(): void
    {
        $root = sys_get_temp_dir() . '/beacon-cache-' . uniqid();
        mkdir($root . '/vendor/switon', 0777, true);
        file_put_contents($root . '/vendor/switon/cli-code-index.json', '{"classes":[]}');

        $this->assertTrue($this->command->callIsVendorCacheValid($root));
    }

    public function testLoadOrBuildVendorCacheReturnsEmptyWhenVendorDirectoryMissing(): void
    {
        $root = sys_get_temp_dir() . '/beacon-novendor-' . uniqid();
        mkdir($root, 0777, true);

        $this->assertSame([], $this->command->callLoadOrBuildVendorCache($root, true));
    }

    public function testLoadOrBuildVendorCacheReturnsCachedJsonWhenValid(): void
    {
        $root = sys_get_temp_dir() . '/beacon-cache-hit-' . uniqid();
        mkdir($root . '/vendor/switon', 0777, true);
        $cached = [
            'classes' => [
                [
                    'class' => 'Cached\\Stub',
                    'file' => '/virtual.php',
                    'summary' => 'from disk',
                    'methods' => [],
                ],
            ],
        ];
        file_put_contents($root . '/vendor/switon/cli-code-index.json', json_encode($cached, JSON_THROW_ON_ERROR));

        $out = $this->command->callLoadOrBuildVendorCache($root, true);

        $this->assertCount(1, $out);
        $this->assertSame('Cached\\Stub', $out[0]['class']);
        $this->assertSame('from disk', $out[0]['summary']);
    }

    public function testLoadOrBuildVendorCacheScansVendorAndWritesCacheFile(): void
    {
        $root = sys_get_temp_dir() . '/beacon-scan-' . uniqid();
        mkdir($root . '/vendor/acme/demo', 0777, true);
        file_put_contents(
            $root . '/vendor/acme/demo/BeaconScanMe.php',
            <<<'PHP'
<?php
/**
 * Acme demo service
 */
class BeaconScanMe {}
PHP
        );

        $out = $this->command->callLoadOrBuildVendorCache($root, true);

        $this->assertNotEmpty($out);
        $classes = array_column($out, 'class');
        $this->assertContains('BeaconScanMe', $classes);
        $cacheFile = $root . '/vendor/switon/cli-code-index.json';
        $this->assertFileExists($cacheFile);
        $decoded = json_decode((string)file_get_contents($cacheFile), true);
        $this->assertIsArray($decoded['classes'] ?? null);
    }

    public function testLoadOrBuildVendorCacheExcludesDevPackageWhenIncludeDevFalse(): void
    {
        $root = sys_get_temp_dir() . '/beacon-devskip-' . uniqid();
        mkdir($root . '/vendor/composer', 0777, true);
        mkdir($root . '/vendor/foo/devpkg', 0777, true);
        file_put_contents(
            $root . '/vendor/foo/devpkg/OnlyDev.php',
            <<<'PHP'
<?php
/** Dev only */
class OnlyDev {}
PHP
        );
        file_put_contents(
            $root . '/vendor/composer/installed.json',
            json_encode(['dev-package-names' => ['foo/devpkg']], JSON_THROW_ON_ERROR)
        );

        $out = $this->command->callLoadOrBuildVendorCache($root, false);

        $this->assertSame([], $out);
    }

    public function testScanPathForClassSummariesReturnsSummariesForPhpFiles(): void
    {
        $dir = sys_get_temp_dir() . '/beacon-scanpath-' . uniqid();
        mkdir($dir, 0777, true);
        file_put_contents(
            $dir . '/One.php',
            <<<'PHP'
<?php
namespace X;
/** One summary */
class One {}
PHP
        );

        $out = $this->command->callScanPathForClassSummaries($dir);

        $this->assertCount(1, $out);
        $this->assertSame('X\\One', $out[0]['class']);
        $this->assertSame('One summary', $out[0]['summary']);
    }

    public function testScanVendorForClassSummariesSkipsComposerDirectory(): void
    {
        $vendor = sys_get_temp_dir() . '/beacon-vscan-' . uniqid();
        mkdir($vendor . '/composer', 0777, true);
        file_put_contents($vendor . '/composer/autoload.php', '<?php');

        $out = $this->command->callScanVendorForClassSummaries($vendor, []);

        $this->assertSame([], $out);
    }

    private function makeCommand(): CodeCommandHarness
    {
        $container = new Container([
            ConsoleInterface::class => $this->console,
        ]);

        return $container->make(CodeCommandHarness::class);
    }
}

class CodeCommandHarness extends CodeCommand
{
    public function callResolvePath(string $path): string
    {
        return $this->resolvePath($path);
    }

    public function callCollectPhpFiles(string $dir): array
    {
        return $this->collectPhpFiles($dir);
    }

    public function callVisibility(int $modifiers): string
    {
        return $this->visibility($modifiers);
    }

    public function callExtractSummaryFromDocblock(string $doc): string
    {
        return $this->extractSummaryFromDocblock($doc);
    }

    public function callExtractClassSummariesFromSource(string $code, string $file): array
    {
        return $this->extractClassSummariesFromSource($code, $file);
    }

    public function callGetVendorDevPackageNames(string $cwd): array
    {
        return $this->getVendorDevPackageNames($cwd);
    }

    public function callGetVendorCachePath(string $cwd): string
    {
        return $this->getVendorCachePath($cwd);
    }

    public function callIsVendorCacheValid(string $cwd): bool
    {
        return $this->isVendorCacheValid($cwd);
    }

    /** @return list<array{class: string, file: string, summary: string, methods: list<array{name: string, summary: string}>}> */
    public function callLoadOrBuildVendorCache(string $cwd, bool $includeDev): array
    {
        return $this->loadOrBuildVendorCache($cwd, $includeDev);
    }

    /** @return list<array{class: string, file: string, summary: string, methods: list<array{name: string, summary: string}>}> */
    public function callScanVendorForClassSummaries(string $vendorDir, array $devPackageNames): array
    {
        return $this->scanVendorForClassSummaries($vendorDir, $devPackageNames);
    }

    /** @return list<array{class: string, file: string, summary: string, methods: list<array{name: string, summary: string}>}> */
    public function callScanPathForClassSummaries(string $dir): array
    {
        return $this->scanPathForClassSummaries($dir);
    }
}
