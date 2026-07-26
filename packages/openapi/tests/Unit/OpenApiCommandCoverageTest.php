<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit;

use Switon\Core\ConsoleInterface;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\OpenApi\Command\OpenApiCommand;
use Switon\OpenApi\DocumentBuilderInterface;
use Switon\OpenApi\LinterInterface;
use Switon\OpenApi\YmlSyncInterface;
use Switon\Testing\Container;
use Switon\OpenApi\Tests\TestCase;
use RuntimeException;

class OpenApiCommandCoverageTest extends TestCase
{
    public function testExportActionWritesDefaultDocumentFile(): void
    {
        $root = sys_get_temp_dir() . '/openapi_export_' . uniqid('', true);
        $this->assertTrue(mkdir($root . '/openapi', 0777, true));

        $documentBuilder = $this->createMock(DocumentBuilderInterface::class);
        $documentBuilder->expects($this->once())
            ->method('build')
            ->with($root . '/openapi', false, false)
            ->willReturn([
                'openapi' => '3.0.3',
                'info' => ['title' => 'Demo', 'version' => '1'],
                'paths' => [],
                'components' => [],
            ]);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('writeLn')
            ->with($root . '/openapi/dist/openapi.json');

        $command = $this->makeCommand($root, [
            'documentBuilder' => $documentBuilder,
            'console' => $console,
        ]);

        $code = $command->exportAction('@root/openapi');

        $this->assertSame(0, $code);
        $this->assertFileExists($root . '/openapi/dist/openapi.json');
        $this->assertStringContainsString('"Demo"', (string)file_get_contents($root . '/openapi/dist/openapi.json'));

        $this->removeDir($root);
    }

    public function testExportActionReportsFailureWhenDocumentBuilderThrows(): void
    {
        $root = sys_get_temp_dir() . '/openapi_export_fail_' . uniqid('', true);
        $this->assertTrue(mkdir($root . '/openapi', 0777, true));

        $documentBuilder = $this->createMock(DocumentBuilderInterface::class);
        $documentBuilder->expects($this->once())
            ->method('build')
            ->willThrowException(new RuntimeException('boom'));

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with(
                'OpenAPI export failed: {message}',
                ['message' => 'boom'],
                1,
            )
            ->willReturn(1);

        $command = $this->makeCommand($root, [
            'documentBuilder' => $documentBuilder,
            'console' => $console,
        ]);

        $this->assertSame(1, $command->exportAction('@root/openapi'));

        $this->removeDir($root);
    }

    public function testExportActionWritesJsonToCustomOutWithoutPrintingPathLine(): void
    {
        $root = sys_get_temp_dir() . '/openapi_export_json_' . uniqid('', true);
        $this->assertTrue(mkdir($root . '/openapi', 0777, true));

        $documentBuilder = $this->createMock(DocumentBuilderInterface::class);
        $documentBuilder->expects($this->once())
            ->method('build')
            ->with($root . '/openapi', true, true)
            ->willReturn([
                'openapi' => '3.0.3',
                'info' => ['title' => 'Demo', 'version' => '1'],
                'paths' => [],
                'components' => [],
            ]);

        $consoleWrites = [];
        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->never())->method('writeLn');
        $console->method('write')->willReturnCallback(static function (string $text) use (&$consoleWrites): void {
            $consoleWrites[] = $text;
        });

        $command = $this->makeCommand($root, [
            'documentBuilder' => $documentBuilder,
            'console' => $console,
        ]);

        $out = $root . '/out/custom-openapi.json';
        $code = $command->exportAction('@root/openapi', $out, true, true, true);

        $this->assertSame(0, $code);
        $this->assertFileExists($out);
        $this->assertCount(2, $consoleWrites);
        $this->assertStringContainsString('"Demo"', $consoleWrites[0]);
        $this->assertSame("\n", $consoleWrites[1]);

        $this->removeDir($root);
    }

    public function testLintActionReportsWarningsAndSuccess(): void
    {
        $root = sys_get_temp_dir() . '/openapi_lint_' . uniqid('', true);
        $this->assertTrue(mkdir($root . '/openapi', 0777, true));

        $linter = $this->createMock(LinterInterface::class);
        $linter->expects($this->once())
            ->method('lint')
            ->with($root . '/openapi')
            ->willReturn([
                'errors' => [],
                'warnings' => ['Missing responses at /a get'],
            ]);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('warning')
            ->with('[lint] Missing responses at /a get');
        $console->expects($this->once())
            ->method('success')
            ->with(
                'open-api:lint ok: {errors} error(s), {warnings} warning(s).',
                ['errors' => 0, 'warnings' => 1],
            );

        $command = $this->makeCommand($root, [
            'linter' => $linter,
            'console' => $console,
        ]);

        $this->assertSame(0, $command->lintAction('@root/openapi'));

        $this->removeDir($root);
    }

    public function testLintActionReportsFailureWhenErrorsExist(): void
    {
        $root = sys_get_temp_dir() . '/openapi_lint_fail_' . uniqid('', true);
        $this->assertTrue(mkdir($root . '/openapi', 0777, true));

        $linter = $this->createMock(LinterInterface::class);
        $linter->expects($this->once())
            ->method('lint')
            ->willReturn([
                'errors' => ['Duplicate operationId: demo::show'],
                'warnings' => ['Missing responses at /a get'],
            ]);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('warning')
            ->with('[lint] Missing responses at /a get');
        $console->expects($this->once())
            ->method('writeLn')
            ->with('[lint:error] Duplicate operationId: demo::show');
        $console->expects($this->once())
            ->method('error')
            ->with(
                'open-api:lint failed: {errors} error(s), {warnings} warning(s).',
                ['errors' => 1, 'warnings' => 1],
                1,
            )
            ->willReturn(1);

        $command = $this->makeCommand($root, [
            'linter' => $linter,
            'console' => $console,
        ]);

        $this->assertSame(1, $command->lintAction('@root/openapi'));

        $this->removeDir($root);
    }

    public function testLintActionFailsInStrictModeWhenWarningsExist(): void
    {
        $root = sys_get_temp_dir() . '/openapi_lint_strict_' . uniqid('', true);
        $this->assertTrue(mkdir($root . '/openapi', 0777, true));

        $linter = $this->createMock(LinterInterface::class);
        $linter->expects($this->once())
            ->method('lint')
            ->with($root . '/openapi')
            ->willReturn([
                'errors' => [],
                'warnings' => ['Missing responses at /a get'],
            ]);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('warning')
            ->with('[lint] Missing responses at /a get');
        $console->expects($this->once())
            ->method('error')
            ->with(
                'open-api:lint failed in strict mode: {warnings} warning(s).',
                ['warnings' => 1],
                1,
            )
            ->willReturn(1);

        $command = $this->makeCommand($root, [
            'linter' => $linter,
            'console' => $console,
        ]);

        $this->assertSame(1, $command->lintAction('@root/openapi', true));

        $this->removeDir($root);
    }

    public function testExportActionFailsWhenRootIsNotDirectory(): void
    {
        $root = sys_get_temp_dir() . '/openapi_export_not_dir_' . uniqid('', true);
        $this->assertTrue(mkdir($root, 0777, true));
        file_put_contents($root . '/openapi', 'not-a-dir');

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with(
                'OpenAPI root is not a directory: {path}',
                ['path' => $root . '/openapi'],
                1,
            )
            ->willReturn(1);

        $pathAlias = (new Container())->get(PathAliasInterface::class);
        $pathAlias->set('@root', $root);
        $command = $this->makeCommand($root, [
            'console' => $console,
            'pathAlias' => $pathAlias,
        ]);

        $this->assertSame(1, $command->exportAction('@root/openapi'));

        unlink($root . '/openapi');
        @rmdir($root);
    }

    public function testLintActionFailsWhenRootIsNotDirectory(): void
    {
        $root = sys_get_temp_dir() . '/openapi_lint_not_dir_' . uniqid('', true);
        mkdir($root, 0777, true);
        file_put_contents($root . '/openapi', 'file');

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with('OpenAPI root is not a directory: {path}', ['path' => $root . '/openapi'], 1)
            ->willReturn(1);

        $pathAlias = (new Container())->get(PathAliasInterface::class);
        $pathAlias->set('@root', $root);

        $command = $this->makeCommand($root, ['console' => $console, 'pathAlias' => $pathAlias]);

        $this->assertSame(1, $command->lintAction('@root/openapi'));

        unlink($root . '/openapi');
        @rmdir($root);
    }

    public function testLintActionReportsFailureWhenLinterThrows(): void
    {
        $root = sys_get_temp_dir() . '/openapi_lint_throw_' . uniqid('', true);
        $this->assertTrue(mkdir($root . '/openapi', 0777, true));

        $linter = $this->createStub(LinterInterface::class);
        $linter->method('lint')->willThrowException(new RuntimeException('lint boom'));

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with('open-api:lint failed: {message}', ['message' => 'lint boom'], 1)
            ->willReturn(1);

        $command = $this->makeCommand($root, ['linter' => $linter, 'console' => $console]);

        $this->assertSame(1, $command->lintAction('@root/openapi'));

        $this->removeDir($root);
    }

    public function testSyncActionFailsWhenRootIsNotDirectory(): void
    {
        $root = sys_get_temp_dir() . '/openapi_sync_not_dir_' . uniqid('', true);
        mkdir($root, 0777, true);
        file_put_contents($root . '/openapi', 'file');

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with('OpenAPI root is not a directory: {path}', ['path' => $root . '/openapi'], 1)
            ->willReturn(1);

        $pathAlias = (new Container())->get(PathAliasInterface::class);
        $pathAlias->set('@root', $root);

        $command = $this->makeCommand($root, ['console' => $console, 'pathAlias' => $pathAlias]);

        $this->assertSame(1, $command->syncAction('@root/openapi'));

        unlink($root . '/openapi');
        @rmdir($root);
    }

    public function testSyncActionPrintsWarningsAndNothingWrittenMessage(): void
    {
        $root = sys_get_temp_dir() . '/openapi_sync_empty_' . uniqid('', true);
        $this->assertTrue(mkdir($root . '/openapi', 0777, true));

        $ymlSync = $this->createStub(YmlSyncInterface::class);
        $ymlSync->method('sync')->willReturn(['written' => [], 'warnings' => ['Already up to date']]);

        $warnings = [];
        $lines = [];
        $console = $this->createStub(ConsoleInterface::class);
        $console->method('warning')->willReturnCallback(static function (string $msg) use (&$warnings): void {
            $warnings[] = $msg;
        });
        $console->method('writeLn')->willReturnCallback(static function (string $line = '') use (&$lines): void {
            $lines[] = $line;
        });

        $command = $this->makeCommand($root, ['ymlSync' => $ymlSync, 'console' => $console]);

        $this->assertSame(0, $command->syncAction('@root/openapi'));
        $this->assertSame(['Already up to date'], $warnings);
        $this->assertStringContainsString('nothing written', $lines[0] ?? '');

        $this->removeDir($root);
    }

    public function testInitActionReturnsNonZeroWhenSyncFailsDuringInit(): void
    {
        $root = sys_get_temp_dir() . '/openapi_init_sync_fail_' . uniqid('', true);
        $pathAlias = (new Container())->get(PathAliasInterface::class);
        $pathAlias->set('@root', $root);

        $ymlSync = $this->createStub(YmlSyncInterface::class);
        $ymlSync->method('sync')->willThrowException(new RuntimeException('sync failed'));

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with('open-api:sync failed: {message}', ['message' => 'sync failed'], 1)
            ->willReturn(1);

        $command = $this->makeCommand($root, [
            'pathAlias' => $pathAlias,
            'ymlSync' => $ymlSync,
            'console' => $console,
        ]);

        $this->assertSame(1, $command->initAction('@root/openapi'));

        $this->removeDir($root);
    }

    public function testSyncActionReportsFailureWhenSyncThrows(): void
    {
        $root = sys_get_temp_dir() . '/openapi_sync_fail_' . uniqid('', true);
        $this->assertTrue(mkdir($root . '/openapi', 0777, true));

        $ymlSync = $this->createMock(YmlSyncInterface::class);
        $ymlSync->expects($this->once())
            ->method('sync')
            ->willThrowException(new RuntimeException('sync boom'));

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with(
                'open-api:sync failed: {message}',
                ['message' => 'sync boom'],
                1,
            )
            ->willReturn(1);

        $command = $this->makeCommand($root, [
            'ymlSync' => $ymlSync,
            'console' => $console,
        ]);

        $this->assertSame(1, $command->syncAction('@root/openapi'));

        $this->removeDir($root);
    }

    public function testInitActionSkipsSyncWhenNoSyncFlagIsSet(): void
    {
        $root = sys_get_temp_dir() . '/openapi_init_no_sync_' . uniqid('', true);
        $pathAlias = (new Container())->get(PathAliasInterface::class);
        $pathAlias->set('@root', $root);

        $ymlSync = $this->createMock(YmlSyncInterface::class);
        $ymlSync->expects($this->never())->method('sync');

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('writeLn')
            ->with($root . '/openapi');

        $command = $this->makeCommand($root, [
            'pathAlias' => $pathAlias,
            'ymlSync' => $ymlSync,
            'console' => $console,
        ]);

        $code = $command->initAction('@root/openapi', true);

        $this->assertSame(0, $code);
        $this->assertFileExists($root . '/openapi/openapi.yml');

        $this->removeDir($root);
    }

    public function testInitActionRunsSyncAndWritesOptionalScaffoldFiles(): void
    {
        $root = sys_get_temp_dir() . '/openapi_init_sync_' . uniqid('', true);
        $pathAlias = (new Container())->get(PathAliasInterface::class);
        $pathAlias->set('@root', $root);

        $ymlSync = $this->createMock(YmlSyncInterface::class);
        $ymlSync->expects($this->once())
            ->method('sync')
            ->with($root . '/openapi', '', '', false)
            ->willReturn([
                'written' => [$root . '/openapi/controllers/demo.yml'],
                'warnings' => [],
            ]);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->exactly(2))
            ->method('writeLn');

        $command = $this->makeCommand($root, [
            'pathAlias' => $pathAlias,
            'ymlSync' => $ymlSync,
            'console' => $console,
        ]);

        $code = $command->initAction('@root/openapi');

        $this->assertSame(0, $code);
        $this->assertFileExists($root . '/openapi/openapi.yml');
        $this->assertFileExists($root . '/openapi/README.md');
        $this->assertFileExists($root . '/openapi/recommended.example.yml');
        $this->assertFileExists($root . '/openapi/components/entity.example.yml');

        $this->removeDir($root);
    }

    public function testInitActionFailsWhenAlreadyInitialized(): void
    {
        $root = sys_get_temp_dir() . '/openapi_init_exists_' . uniqid('', true);
        $this->assertTrue(mkdir($root . '/openapi', 0777, true));
        file_put_contents($root . '/openapi/openapi.yml', "openapi: 3.0.3\n");

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with(
                'Already initialized (openapi.yml exists): {path}',
                ['path' => $root . '/openapi/openapi.yml'],
                1,
            )
            ->willReturn(1);

        $command = $this->makeCommand($root, [
            'console' => $console,
        ]);

        $this->assertSame(1, $command->initAction('@root/openapi', true));

        $this->removeDir($root);
    }

    public function testInitActionFailsWhenScaffoldFilesAreMissing(): void
    {
        $root = sys_get_temp_dir() . '/openapi_init_missing_' . uniqid('', true);
        $this->assertTrue(mkdir($root, 0777, true));

        $pathAlias = (new Container())->get(PathAliasInterface::class);
        $pathAlias->set('@root', $root);
        $pathAlias->set('@switon.openapi.resources', $root . '/missing-resources');

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())
            ->method('error')
            ->with(
                'OpenAPI scaffold files are missing from package: {path}',
                ['path' => $pathAlias->resolve('@switon.openapi.resources/scaffold')],
                1,
            )
            ->willReturn(1);

        $command = $this->makeCommand($root, [
            'console' => $console,
            'filesystem' => $this->missingScaffoldFilesystem($pathAlias),
            'pathAlias' => $pathAlias,
        ]);

        $this->assertSame(1, $command->initAction('@root/openapi', true));

        $this->removeDir($root);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    protected function makeCommand(string $root, array $parameters = []): OpenApiCommand
    {
        if (isset($parameters['pathAlias']) && $parameters['pathAlias'] instanceof PathAliasInterface) {
            $pathAlias = $parameters['pathAlias'];
        } else {
            $pathAlias = (new Container())->get(PathAliasInterface::class);
            $pathAlias->set('@root', $root);
        }
        unset($parameters['pathAlias']);
        $parameters['pathAlias'] = $pathAlias;

        if (!isset($parameters['filesystem'])) {
            $parameters['filesystem'] = $this->filesystemStub($pathAlias);
        }

        return $this->make(OpenApiCommand::class, $parameters);
    }

    protected function filesystemStub(PathAliasInterface $pathAlias): FilesystemInterface
    {
        $filesystem = $this->createStub(FilesystemInterface::class);
        $filesystem->method('resolve')->willReturnCallback(
            static fn (string $path): string => $pathAlias->resolve($path)
        );
        $filesystem->method('exists')->willReturnCallback(static fn (string $path): bool => is_file($path) || is_dir($path));
        $filesystem->method('isDir')->willReturnCallback(static fn (string $path): bool => is_dir($path));
        $filesystem->method('mkdir')->willReturnCallback(static function (string $path): void {
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        });
        $filesystem->method('read')->willReturnCallback(static fn (string $path): string => (string)file_get_contents($path));
        $filesystem->method('write')->willReturnCallback(static function (string $path, string $content): void {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($path, $content);
        });

        return $filesystem;
    }

    protected function missingScaffoldFilesystem(PathAliasInterface $pathAlias): FilesystemInterface
    {
        $base = $pathAlias->resolve('@switon.openapi.resources/scaffold');
        $filesystem = $this->filesystemStub($pathAlias);
        $filesystem->method('exists')->willReturnCallback(static function (string $path) use ($base): bool {
            if ($path === $base . '/openapi.yml' || $path === $base . '/README.md') {
                return false;
            }

            return is_file($path) || is_dir($path);
        });

        return $filesystem;
    }

    protected function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->removeDir($child);
                continue;
            }

            unlink($child);
        }

        @rmdir($path);
    }
}
