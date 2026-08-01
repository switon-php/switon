<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Docfs\Git;
use App\Mcp\Tool\MkbookTool;
use App\Mcp\Tool\MvFileTool;
use App\Mcp\Tool\WriteTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Mcp\ToolResult;
use Switon\Testing\TestCase;

#[CoversClass(MvFileTool::class)]
final class MvFileToolTest extends TestCase
{
    private string $booksRoot;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->booksRoot = sys_get_temp_dir() . '/docfs_mv_' . bin2hex(random_bytes(8));
        mkdir($this->booksRoot, 0775, true);

        $this->container->get(PathAliasInterface::class)->set('@books', $this->booksRoot);

        $credential = new class () implements CredentialInterface {
            public function payload(): array
            {
                return [
                    'name' => 'Test User',
                    'email' => 'test@example.com',
                    'books' => ['*' => CredentialInterface::PERM_WRITE],
                ];
            }
        };
        $this->container->set(CredentialInterface::class, $credential);
    }

    protected function tearDown(): void
    {
        if (isset($this->booksRoot) && is_dir($this->booksRoot)) {
            $this->removeDirectory($this->booksRoot);
        }
        parent::tearDown();
    }

    public function testRenameRewritesInBookMarkdownRefs(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $write = $this->make(WriteTool::class);
        $write->call('write', [
            'book' => 'demo',
            'file' => 'first-brew.md',
            'content' => "# First\n\nSee also not-first-brew.md elsewhere.\n",
            'git_message' => 'add first',
        ]);
        $write->call('write', [
            'book' => 'demo',
            'file' => 'index.md',
            'sha' => $this->blobSha(''),
            'content' => "# Book\n\n- [First](first-brew.md)\n- keep not-first-brew.md literal\n",
            'git_message' => 'link first',
        ]);

        $sha = $this->blobSha("# First\n\nSee also not-first-brew.md elsewhere.\n");
        $result = $this->make(MvFileTool::class)->call('mv_file', [
            'book' => 'demo',
            'file' => 'first-brew.md',
            'to' => 'first-infusion.md',
            'sha' => $sha,
            'git_message' => 'rename brew note',
        ]);

        $fs = $this->container->get(FilesystemInterface::class);
        $this->assertTrue($result['moved']);
        $this->assertTrue($result['links_updated']);
        $this->assertSame('first-infusion.md', $result['to']);
        $this->assertStringContainsString('rewritten', $result['note']);
        $this->assertFalse($fs->exists('@books/demo/first-brew.md'));
        $this->assertTrue($fs->exists('@books/demo/first-infusion.md'));
        $this->assertSame(
            "# Book\n\n- [First](first-infusion.md)\n- keep not-first-brew.md literal\n",
            $fs->read('@books/demo/index.md'),
        );
        $this->assertCount(1, $result['rewritten']);
        $this->assertSame('index.md', $result['rewritten'][0]['file']);
    }

    public function testSchemaDescribesRenameAndDelete(): void
    {
        $schema = $this->make(MvFileTool::class)->schema('mv_file');

        $this->assertSame(['book', 'file', 'sha', 'git_message'], $schema->definition['inputSchema']['required']);
        $this->assertArrayNotHasKey('to', $schema->definition['inputSchema']['required']);
    }

    public function testDeleteRemovesFile(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $body = "gone\n";
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'note.md',
            'content' => $body,
            'git_message' => 'add note',
        ]);

        $result = $this->make(MvFileTool::class)->call('mv_file', [
            'book' => 'demo',
            'file' => 'note.md',
            'sha' => $this->blobSha($body),
            'git_message' => 'remove note',
        ]);

        $this->assertTrue($result['deleted']);
        $this->assertNull($result['to']);
        $this->assertFalse($this->container->get(FilesystemInterface::class)->exists('@books/demo/note.md'));
    }

    public function testCallRejectsRequiredBookFile(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot delete/rename required book file');

        $this->make(MvFileTool::class)->call('mv_file', [
            'book' => 'demo',
            'file' => 'index.md',
            'sha' => $this->blobSha(''),
            'git_message' => 'nope',
        ]);
    }

    public function testCallShaMismatchReturnsCurrentSha(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $body = "keep\n";
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'note.md',
            'content' => $body,
            'git_message' => 'add note',
        ]);

        $result = $this->make(MvFileTool::class)->call('mv_file', [
            'book' => 'demo',
            'file' => 'note.md',
            'to' => 'other.md',
            'sha' => str_repeat('0', 7),
            'git_message' => 'rename',
        ]);

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertTrue($result->isError);
        $this->assertSame($this->blobSha($body), $result->content['sha']);
    }

    public function testCallRejectsExistingDestination(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $write = $this->make(WriteTool::class);
        $write->call('write', [
            'book' => 'demo',
            'file' => 'a.md',
            'content' => "a\n",
            'git_message' => 'add a',
        ]);
        $write->call('write', [
            'book' => 'demo',
            'file' => 'b.md',
            'content' => "b\n",
            'git_message' => 'add b',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Destination exists: b.md');

        $this->make(MvFileTool::class)->call('mv_file', [
            'book' => 'demo',
            'file' => 'a.md',
            'to' => 'b.md',
            'sha' => $this->blobSha("a\n"),
            'git_message' => 'rename',
        ]);
    }

    public function testCallMissingFileRaisesNotFound(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('not found');

        $this->make(MvFileTool::class)->call('mv_file', [
            'book' => 'demo',
            'file' => 'missing.md',
            'sha' => str_repeat('0', 7),
            'git_message' => 'rename',
        ]);
    }

    private function blobSha(string $bytes): string
    {
        $git = $this->container->get(Git::class);

        return $git->abbrev($git->hashObject($bytes));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
