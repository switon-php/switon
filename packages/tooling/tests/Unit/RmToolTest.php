<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Path;
use Switon\Tooling\Tests\ToolTestCase;

use const PHP_OS_FAMILY;
use Switon\Tooling\Tool\RmTool;

#[CoversClass(RmTool::class)]
class RmToolTest extends ToolTestCase
{
    /** @var list<string> */
    protected array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempDirs) as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            @chmod($dir, 0700);
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $dir . '/' . $name;
                @chmod($path, 0600);
                @unlink($path);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    public function testSchemaIsShort(): void
    {
        $schema = $this->make(RmTool::class)->schema('rm');

        $this->assertSame(
            'Delete file(s). Prefer this over shell rm. Pass one or more absolute paths.',
            $schema->definition['description'],
        );
        $this->assertSame(['paths'], $schema->definition['inputSchema']['required']);
        $this->assertArrayHasKey('paths', $schema->definition['inputSchema']['properties']);
        $this->assertArrayNotHasKey('path', $schema->definition['inputSchema']['properties']);
    }

    public function testCallRemovesFile(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/gone.txt';
        file_put_contents($file, 'bye');

        $result = $this->callTool($this->make(RmTool::class), 'rm', ['paths' => [$file]]);

        $this->assertSame('success', $result);
        $this->assertFileDoesNotExist($file);
    }

    public function testCallRemovesMultipleFiles(): void
    {
        $dir = $this->tempDir();
        $a = $dir . '/a.txt';
        $b = $dir . '/b.txt';
        file_put_contents($a, 'a');
        file_put_contents($b, 'b');

        $result = $this->callTool($this->make(RmTool::class), 'rm', ['paths' => [$a, $b, $a]]);

        $this->assertSame('success', $result);
        $this->assertFileDoesNotExist($a);
        $this->assertFileDoesNotExist($b);
    }

    public function testCallListsOnlyFailures(): void
    {
        $dir = $this->tempDir();
        $kept = $dir . '/kept.txt';
        $missing = Path::absolute($dir . '/missing.txt');
        file_put_contents($kept, 'ok');

        $result = $this->callTool($this->make(RmTool::class), 'rm', ['paths' => [$kept, $missing]]);

        $this->assertSame([
            ['path' => $missing, 'error' => 'File not found'],
        ], $result['failed']);
        $this->assertFileDoesNotExist($kept);
    }

    public function testCallRejectsRelativePath(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/gone.txt';
        file_put_contents($file, 'bye');

        $this->expectException(\Switon\Core\Exception::class);
        $this->expectExceptionMessage('Path must be absolute');

        $this->callTool(
            $this->make(RmTool::class),
            'rm',
            ['paths' => ['gone.txt']],
            workspace: $dir,
        );
    }

    public function testCallRejectsMissingFile(): void
    {
        $missing = Path::absolute($this->tempDir() . '/missing.txt');

        $result = $this->callTool($this->make(RmTool::class), 'rm', ['paths' => [$missing]]);

        $this->assertSame([
            ['path' => $missing, 'error' => 'File not found'],
        ], $result['failed']);
    }

    public function testCallRejectsDirectoryPath(): void
    {
        $dir = Path::absolute($this->tempDir());

        $result = $this->callTool($this->make(RmTool::class), 'rm', ['paths' => [$dir]]);

        $this->assertSame([
            ['path' => $dir, 'error' => 'File not found'],
        ], $result['failed']);
    }

    public function testCallRaisesWhenUnlinkFails(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('The owner can still delete a file in a mode-0555 directory.');
        }

        $dir = $this->tempDir();
        $file = $dir . '/locked.txt';
        file_put_contents($file, 'x');
        chmod($dir, 0555);
        set_error_handler(static fn (): bool => true);

        try {
            $result = $this->callTool($this->make(RmTool::class), 'rm', ['paths' => [$file]]);
            $this->assertSame([
                ['path' => $file, 'error' => 'Failed to delete file'],
            ], $result['failed']);
            $this->assertFileExists($file);
        } finally {
            restore_error_handler();
            chmod($dir, 0700);
        }
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/switon-agent-file-rm-' . uniqid('', true);
        mkdir($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }
}
