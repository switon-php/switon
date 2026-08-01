<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Docfs\Git;
use App\Mcp\Tool\DiffTool;
use App\Mcp\Tool\MkbookTool;
use App\Mcp\Tool\WriteTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\PathAliasInterface;
use Switon\Testing\TestCase;

#[CoversClass(DiffTool::class)]
final class DiffToolTest extends TestCase
{
    private string $booksRoot;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->booksRoot = sys_get_temp_dir() . '/docfs_diff_' . bin2hex(random_bytes(8));
        mkdir($this->booksRoot, 0775, true);
        $this->container->get(PathAliasInterface::class)->set('@books', $this->booksRoot);
        $this->container->set(CredentialInterface::class, new class () implements CredentialInterface {
            public function payload(): array
            {
                return [
                    'name' => 'Test User',
                    'email' => 'test@example.com',
                    'books' => ['*' => CredentialInterface::PERM_WRITE],
                ];
            }
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->booksRoot) && is_dir($this->booksRoot)) {
            $this->removeDirectory($this->booksRoot);
        }
        parent::tearDown();
    }

    public function testSchemaRequiresBookAndRev(): void
    {
        $schema = $this->make(DiffTool::class)->schema('diff');

        $this->assertSame(['book', 'rev'], $schema->definition['inputSchema']['required']);
        $this->assertSame('HEAD', $schema->definition['inputSchema']['properties']['rev2']['default']);
    }

    public function testCallDiffsWholeBook(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'overview.md',
            'content' => "hello\n",
            'git_message' => 'Add overview',
        ]);

        $result = $this->make(DiffTool::class)->call('diff', [
            'book' => 'demo',
            'rev' => 'HEAD~1',
            'rev2' => 'HEAD',
        ]);

        $this->assertSame('demo', $result['book']);
        $this->assertSame('HEAD~1', $result['rev']);
        $this->assertSame('HEAD', $result['rev2']);
        $this->assertSame(Git::SHA_ABBREV, strlen($result['commit']));
        $this->assertSame(Git::SHA_ABBREV, strlen($result['commit2']));
        $this->assertStringContainsString('overview.md', $result['diff']);
        $this->assertFalse($result['truncated']);
        $this->assertArrayNotHasKey('file', $result);
    }

    public function testCallScopesToOneFile(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'overview.md',
            'content' => "hello\n",
            'git_message' => 'Add overview',
        ]);

        $result = $this->make(DiffTool::class)->call('diff', [
            'book' => 'demo',
            'rev' => 'HEAD~1',
            'rev2' => '',
            'file' => 'overview.md',
        ]);

        $this->assertSame('overview.md', $result['file']);
        $this->assertSame('HEAD', $result['rev2']);
        $this->assertStringContainsString('overview.md', $result['diff']);
    }

    public function testCallRejectsInvalidFileName(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid file name');

        $this->make(DiffTool::class)->call('diff', [
            'book' => 'demo',
            'rev' => 'HEAD',
            'rev2' => 'HEAD',
            'file' => 'nested/a.md',
        ]);
    }

    public function testCallRejectsEmptyRev(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rev is required');

        $this->make(DiffTool::class)->call('diff', [
            'book' => 'demo',
            'rev' => '',
            'rev2' => 'HEAD',
        ]);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
