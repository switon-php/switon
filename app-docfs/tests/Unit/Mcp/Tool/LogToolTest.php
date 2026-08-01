<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Docfs\Git;
use App\Mcp\Tool\LogTool;
use App\Mcp\Tool\MkbookTool;
use App\Mcp\Tool\WriteTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\PathAliasInterface;
use Switon\Testing\TestCase;

#[CoversClass(LogTool::class)]
final class LogToolTest extends TestCase
{
    private string $booksRoot;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->booksRoot = sys_get_temp_dir() . '/docfs_log_' . bin2hex(random_bytes(8));
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

    public function testSchemaDefaultsLimit(): void
    {
        $schema = $this->make(LogTool::class)->schema('log');

        $this->assertSame(['book'], $schema->definition['inputSchema']['required']);
        $this->assertSame(20, $schema->definition['inputSchema']['properties']['limit']['default']);
    }

    public function testCallListsBookCommits(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'overview.md',
            'content' => "hello\n",
            'git_message' => 'Add overview',
        ]);

        $result = $this->make(LogTool::class)->call('log', [
            'book' => 'demo',
            'limit' => 20,
        ]);

        $this->assertSame('demo', $result['book']);
        $this->assertArrayNotHasKey('file', $result);
        $this->assertGreaterThanOrEqual(2, count($result['commits']));
        $this->assertSame('Add overview', $result['commits'][0]['message']);
        $this->assertSame(Git::SHA_ABBREV, strlen($result['commits'][0]['commit']));
        $this->assertNotSame('', $result['commits'][0]['time']);
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

        $result = $this->make(LogTool::class)->call('log', [
            'book' => 'demo',
            'file' => 'overview.md',
            'limit' => 0,
        ]);

        $this->assertSame('overview.md', $result['file']);
        $this->assertCount(1, $result['commits']);
        $this->assertSame('Add overview', $result['commits'][0]['message']);
    }

    public function testCallClampsLimit(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $result = $this->make(LogTool::class)->call('log', [
            'book' => 'demo',
            'limit' => 500,
        ]);

        $this->assertCount(1, $result['commits']);
    }

    public function testCallRejectsInvalidFileName(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid file name');

        $this->make(LogTool::class)->call('log', [
            'book' => 'demo',
            'file' => '../secret.md',
            'limit' => 20,
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
