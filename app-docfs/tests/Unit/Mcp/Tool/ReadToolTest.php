<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Docfs\Git;
use App\Mcp\Tool\MkbookTool;
use App\Mcp\Tool\ReadTool;
use App\Mcp\Tool\WriteTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\PathAliasInterface;
use Switon\Testing\TestCase;

#[CoversClass(ReadTool::class)]
final class ReadToolTest extends TestCase
{
    private string $booksRoot;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->booksRoot = sys_get_temp_dir() . '/docfs_read_' . bin2hex(random_bytes(8));
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

    public function testSchemaDescribesMarkdownRead(): void
    {
        $schema = $this->make(ReadTool::class)->schema('read');

        $this->assertStringContainsString('lines[]', $schema->definition['description']);
        $this->assertSame(['book', 'file'], $schema->definition['inputSchema']['required']);
    }

    public function testCallReadsWorkingTree(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'overview.md',
            'content' => "hello\n",
            'git_message' => 'Add overview',
        ]);

        $result = $this->make(ReadTool::class)->call('read', [
            'book' => 'demo',
            'file' => 'overview.md',
        ]);

        $this->assertSame('overview.md', $result['file']);
        $this->assertSame(['hello', ''], $result['lines']);
        $this->assertSame($this->blobSha("hello\n"), $result['sha']);
        $this->assertSame(Git::SHA_ABBREV, strlen($result['commit']));
        $this->assertArrayNotHasKey('rev', $result);
    }

    public function testCallReadsHistoricalRev(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'index.md',
            'content' => "v2\n",
            'sha' => $this->blobSha(''),
            'git_message' => 'Update index',
        ]);

        $result = $this->make(ReadTool::class)->call('read', [
            'book' => 'demo',
            'file' => 'index.md',
            'rev' => 'HEAD~1',
        ]);

        $this->assertSame([''], $result['lines']);
        $this->assertSame('HEAD~1', $result['rev']);
        $this->assertSame(Git::SHA_ABBREV, strlen($result['commit']));
        $this->assertSame($this->blobSha(''), $result['sha']);
    }

    public function testCallRejectsInvalidFileName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid file name');

        $this->make(ReadTool::class)->call('read', [
            'book' => 'demo',
            'file' => 'nested/a.md',
        ]);
    }

    public function testCallMissingFileRaisesNotFound(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('not found');

        $this->make(ReadTool::class)->call('read', [
            'book' => 'demo',
            'file' => 'missing.md',
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
