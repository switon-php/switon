<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Docfs\Git;
use App\Mcp\Tool\MkbookTool;
use App\Mcp\Tool\WriteTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Mcp\ToolResult;
use Switon\Testing\TestCase;

#[CoversClass(WriteTool::class)]
final class WriteToolTest extends TestCase
{
    private string $booksRoot;

    /** @var object{payload: array<string, mixed>}&CredentialInterface */
    private object $credential;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->booksRoot = sys_get_temp_dir() . '/docfs_write_' . bin2hex(random_bytes(8));
        mkdir($this->booksRoot, 0775, true);

        $this->container->get(PathAliasInterface::class)->set('@books', $this->booksRoot);

        $this->credential = new class () implements CredentialInterface {
            /** @var array<string, mixed> */
            public array $payload = [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'books' => ['*' => CredentialInterface::PERM_WRITE],
            ];

            public function payload(): array
            {
                return $this->payload;
            }
        };
        $this->container->set(CredentialInterface::class, $this->credential);
    }

    protected function tearDown(): void
    {
        if (isset($this->booksRoot) && is_dir($this->booksRoot)) {
            $this->removeDirectory($this->booksRoot);
        }
        parent::tearDown();
    }

    public function testSchemaRequiresContentAndMessage(): void
    {
        $schema = $this->make(WriteTool::class)->schema('write');

        $this->assertSame(['book', 'file', 'content', 'git_message'], $schema->definition['inputSchema']['required']);
    }

    public function testCallUsesFirstLineOfGitMessage(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'overview.md',
            'content' => "hello\n",
            'git_message' => "  Add overview  \nsecond line",
        ]);

        $log = $this->container->get(Git::class)->run('@books/demo', ['log', '-1', '--format=%s'], false);

        $this->assertSame('Add overview', trim($log));
    }

    public function testCallRejectsEmptyGitMessage(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('git_message required');

        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'overview.md',
            'content' => "hello\n",
            'git_message' => " \n",
        ]);
    }

    public function testCallRejectsLongGitMessage(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('git_message too long (max 100)');

        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'overview.md',
            'content' => "hello\n",
            'git_message' => str_repeat('a', 101),
        ]);
    }

    public function testCallCreatesFileWithoutSha(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $result = $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'overview.md',
            'content' => "hello\n",
            'git_message' => 'Add overview',
        ]);

        $this->assertSame('overview.md', $result['file']);
        $this->assertSame($this->blobSha("hello\n"), $result['sha']);
        $this->assertArrayNotHasKey('content', $result);
        $this->assertSame("hello\n", $this->container->get(FilesystemInterface::class)->read('@books/demo/overview.md'));
    }

    public function testCallCreatesFileIgnoringProvidedSha(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $result = $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'overview.md',
            'content' => "hello\n",
            'git_message' => 'Add overview',
            'sha' => str_repeat('a', 40),
        ]);

        $this->assertSame($this->blobSha("hello\n"), $result['sha']);
        $this->assertArrayNotHasKey('content', $result);
    }

    public function testCallUpdatesExistingFileWithMatchingSha(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'overview.md',
            'content' => "v1\n",
            'git_message' => 'Add overview',
        ]);

        $result = $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'overview.md',
            'content' => "v2\n",
            'git_message' => 'Update overview',
            'sha' => $this->blobSha("v1\n"),
        ]);

        $this->assertSame($this->blobSha("v2\n"), $result['sha']);
        $this->assertArrayNotHasKey('content', $result);
        $this->assertSame("v2\n", $this->container->get(FilesystemInterface::class)->read('@books/demo/overview.md'));
    }

    public function testCallRequiresShaWhenFileExists(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'overview.md',
            'content' => "v1\n",
            'git_message' => 'Add overview',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sha required when file exists');

        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'overview.md',
            'content' => "v2\n",
            'git_message' => 'Update overview',
        ]);
    }

    public function testCallShaMismatchReturnsCurrentShaWithoutContent(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'overview.md',
            'content' => "v1\n",
            'git_message' => 'Add overview',
        ]);

        $result = $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'overview.md',
            'content' => "v2\n",
            'git_message' => 'Update overview',
            'sha' => str_repeat('0', 7),
        ]);

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertTrue($result->isError);
        $this->assertSame([
            'error' => 'sha_mismatch',
            'file' => 'overview.md',
            'sha' => $this->blobSha("v1\n"),
        ], $result->content);
        $this->assertSame(Git::SHA_ABBREV, strlen($result->content['sha']));
        $this->assertArrayNotHasKey('content', $result->content);
        $this->assertArrayNotHasKey('size', $result->content);
    }

    public function testCallRejectsInvalidFileName(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid file name');

        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'nested/path.md',
            'content' => 'x',
            'git_message' => 'Nope',
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
