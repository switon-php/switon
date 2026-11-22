<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Tooling\Exception;

use const PHP_OS_FAMILY;
use Switon\Tooling\Tests\ToolTestCase;
use Switon\Tooling\Tool\LsTool;

#[CoversClass(LsTool::class)]
class LsToolTest extends ToolTestCase
{
    /** @var list<string> */
    protected array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempDirs) as $dir) {
            $this->removeTree($dir);
        }
        parent::tearDown();
    }

    public function testSchemaIsShort(): void
    {
        $schema = $this->make(LsTool::class)->schema('ls');

        $this->assertSame('List a directory.', $schema->definition['description']);
        $this->assertSame([], $schema->definition['inputSchema']['required'] ?? []);
        $this->assertArrayNotHasKey('default', $schema->definition['inputSchema']['properties']['path']);
        $this->assertStringContainsString('workspace', $schema->definition['inputSchema']['properties']['path']['description']);
    }

    public function testCallListsEmptyDirectory(): void
    {
        $dir = $this->tempDir();

        $result = $this->callTool($this->make(LsTool::class), 'ls', [
            'path' => $dir,
        ]);

        $this->assertSame(['dirs', 'files'], array_keys($result));
        $this->assertSame([], $result['dirs']);
        $this->assertSame([], $result['files']);
    }

    public function testCallDefaultsPathToWorkspace(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/here.txt', 'x');

        $result = $this->callTool($this->make(LsTool::class), 'ls', [], workspace: $dir);

        $this->assertSame([], $result['dirs']);
        $this->assertSame('here.txt', $result['files'][0]['name']);
    }

    public function testCallWithFilesIncludesSize(): void
    {
        $dir = $this->tempDir();
        $content1 = 'alpha';
        $content2 = 'beta';
        file_put_contents($dir . '/alpha.txt', $content1);
        file_put_contents($dir . '/beta.md', $content2);

        $result = $this->callTool($this->make(LsTool::class), 'ls', [
            'path' => $dir,
        ]);

        $this->assertSame([], $result['dirs']);
        $this->assertCount(2, $result['files']);
        $this->assertSame('alpha.txt', $result['files'][0]['name']);
        $this->assertSame(strlen($content1), $result['files'][0]['size']);
        $this->assertSame('beta.md', $result['files'][1]['name']);
        $this->assertSame(strlen($content2), $result['files'][1]['size']);
    }

    public function testCallWithSubdirectory(): void
    {
        $dir = $this->tempDir();
        $subDir = $dir . '/subdir';
        mkdir($subDir);
        file_put_contents($dir . '/file.txt', 'file content');

        $result = $this->callTool($this->make(LsTool::class), 'ls', [
            'path' => $dir,
        ]);

        $this->assertSame(['subdir'], $result['dirs']);
        $this->assertCount(1, $result['files']);
        $this->assertSame('file.txt', $result['files'][0]['name']);
        $this->assertArrayNotHasKey('type', $result['files'][0]);
    }

    public function testCallWithNonExistentPathRaises(): void
    {
        $nonExistentPath = sys_get_temp_dir() . '/non-existent-path-' . uniqid('', true);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Path not found');

        $this->callTool($this->make(LsTool::class), 'ls', [
            'path' => $nonExistentPath,
        ]);
    }

    public function testCallWithFilePathRejectsFile(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/note.txt';
        file_put_contents($file, 'x');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Not a directory');

        $this->callTool($this->make(LsTool::class), 'ls', ['path' => $file]);
    }

    public function testCallResultSortedByName(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/zulu.txt', 'z content');
        file_put_contents($dir . '/alpha.txt', 'a content');
        file_put_contents($dir . '/mike.txt', 'm content');

        $result = $this->callTool($this->make(LsTool::class), 'ls', [
            'path' => $dir,
        ]);

        $this->assertSame(['alpha.txt', 'mike.txt', 'zulu.txt'], array_column($result['files'], 'name'));
    }

    public function testCallRaisesWhenScandirFails(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('The owner can still list a mode-0100 directory.');
        }

        $dir = $this->tempDir();
        chmod($dir, 0100);
        set_error_handler(static fn (): bool => true);

        try {
            $this->expectException(Exception::class);
            $this->expectExceptionMessage('Failed to list directory');
            $this->callTool($this->make(LsTool::class), 'ls', ['path' => $dir]);
        } finally {
            restore_error_handler();
            chmod($dir, 0700);
        }
    }

    public function testCallOmitsSizeWhenFilesizeFails(): void
    {
        $dir = $this->tempDir();
        $broken = $dir . '/broken';
        if (!@symlink('/no-such-switon-' . uniqid('', true), $broken)) {
            $this->markTestSkipped('This OS refused the dangling symlink.');
        }
        set_error_handler(static fn (): bool => true);

        try {
            $result = $this->callTool($this->make(LsTool::class), 'ls', ['path' => $dir]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame('broken', $result['files'][0]['name']);
        $this->assertArrayNotHasKey('size', $result['files'][0]);
    }

    public function testCallSpillsFilesAndKeepsDirs(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/keep');
        $name = str_repeat('f', 80);
        for ($index = 0; $index < 20; $index += 1) {
            file_put_contents($dir . '/' . $name . $index . '.txt', 'x');
        }

        $spillDir = $this->spillDir();
        $result = $this->callTool($this->make(LsTool::class, ['budget' => 1]), 'ls', [
            'path' => $dir,
        ], datadir: $spillDir, callId: 'ls');

        $this->assertArrayNotHasKey('path', $result);
        $this->assertArrayNotHasKey('exists', $result);
        $this->assertSame(['keep'], $result['dirs']);
        $this->assertIsString($result['files']);
        $this->assertStringContainsString('recall: @thread/tool-result/ls-files.txt', $result['files']);
        $stored = json_decode((string)file_get_contents($spillDir . '/tool-result/ls-files.txt'), true);
        $this->assertCount(20, $stored);
        $this->assertSame($name . '0.txt', $stored[0]['name']);
    }

    public function testCallSpillsDirsAndKeepsFiles(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/keep.txt', 'x');
        $name = str_repeat('d', 80);
        for ($index = 0; $index < 20; $index += 1) {
            mkdir($dir . '/' . $name . $index);
        }

        $spillDir = $this->spillDir();
        $result = $this->callTool($this->make(LsTool::class, ['budget' => 1]), 'ls', [
            'path' => $dir,
        ], datadir: $spillDir, callId: 'ls');

        $this->assertSame(['name' => 'keep.txt', 'size' => 1], $result['files'][0]);
        $this->assertIsString($result['dirs']);
        $this->assertStringContainsString('recall: @thread/tool-result/ls-dirs.txt', $result['dirs']);
        $stored = json_decode((string)file_get_contents($spillDir . '/tool-result/ls-dirs.txt'), true);
        $this->assertCount(20, $stored);
        $this->assertSame($name . '0', $stored[0]);
    }

    public function testCallSpillsWholeListingWhenFieldsStayUnderBudget(): void
    {
        $dir = $this->tempDir();
        $name = str_repeat('n', 40);
        for ($index = 0; $index < 8; $index += 1) {
            mkdir($dir . '/' . $name . $index);
        }
        for ($index = 0; $index < 12; $index += 1) {
            file_put_contents($dir . '/' . $name . $index . '.txt', 'x');
        }

        $spillDir = $this->spillDir();
        $result = $this->callTool($this->make(LsTool::class, ['budget' => 1]), 'ls', [
            'path' => $dir,
        ], datadir: $spillDir, callId: 'ls');

        $this->assertIsString($result);
        $this->assertStringContainsString('recall: @thread/tool-result/ls.txt', $result);
        $this->assertFileDoesNotExist($spillDir . '/tool-result/ls-dirs.txt');
        $this->assertFileDoesNotExist($spillDir . '/tool-result/ls-files.txt');
        $stored = json_decode((string)file_get_contents($spillDir . '/tool-result/ls.txt'), true);
        $this->assertCount(8, $stored['dirs']);
        $this->assertCount(12, $stored['files']);
    }

    protected function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path)) {
                $this->removeTree($path);
                continue;
            }
            @unlink($path);
        }
        @chmod($dir, 0700);
        @rmdir($dir);
    }


    protected function spillDir(): string
    {
        $dir = $this->tempDir();
        mkdir($dir . '/tool-result');

        return $dir;
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/switon-agent-dirlist-' . uniqid('', true);
        mkdir($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }
}
