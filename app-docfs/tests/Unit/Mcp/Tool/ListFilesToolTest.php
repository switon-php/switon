<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Docfs\Git;
use App\Mcp\Tool\ListFilesTool;
use App\Mcp\Tool\MkbookTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Testing\TestCase;
use Switon\Validating\Exception\ValidateFailedException;

#[CoversClass(ListFilesTool::class)]
final class ListFilesToolTest extends TestCase
{
    private const EMPTY_BLOB_SHA = 'e69de29';

    private string $booksRoot;

    /** @var object{payload: array<string, mixed>}&CredentialInterface */
    private object $credential;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->booksRoot = sys_get_temp_dir() . '/docfs_list_files_' . bin2hex(random_bytes(8));
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

    public function testSchemaRequiresBook(): void
    {
        $tool = $this->make(ListFilesTool::class);
        $schema = $tool->schema('list_files');

        $this->assertSame('List all files under a book with content shas (docs + assets).', $schema->definition['description']);
        $this->assertSame(['book'], $schema->definition['inputSchema']['required']);
        $this->assertArrayHasKey('book', $schema->definition['inputSchema']['properties']);
        $this->assertArrayNotHasKey('recursive', $schema->definition['inputSchema']['properties']);
    }

    public function testCallListsAllFileShas(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $fs = $this->container->get(FilesystemInterface::class);
        $fs->mkdir('@books/demo/assets', 0775);
        $fs->write('@books/demo/assets/abc.png', 'png');
        $this->container->get(Git::class)->commit(
            '@books/demo',
            'Add asset',
            'Test User',
            'test@example.com',
        );

        $result = $this->make(ListFilesTool::class)->call('list_files', ['book' => 'demo']);

        $this->assertSame('demo', $result['book']);
        $this->assertNotSame('', $result['commit']);
        $byFile = [];
        foreach ($result['entries'] as $entry) {
            $byFile[$entry['file']] = $entry;
        }
        $this->assertArrayHasKey('index.md', $byFile);
        $this->assertArrayHasKey('AGENT.md', $byFile);
        $this->assertArrayHasKey('assets/abc.png', $byFile);
        $this->assertSame(self::EMPTY_BLOB_SHA, $byFile['index.md']['sha']);
        $this->assertSame(3, $byFile['assets/abc.png']['size']);
        $this->assertSame(Git::SHA_ABBREV, strlen($byFile['index.md']['sha']));
    }

    public function testCallWalksFilesWhenBookHasNoGit(): void
    {
        $root = $this->booksRoot . '/loose';
        mkdir($root . '/nested', 0775, true);
        file_put_contents($root . '/note.md', "hello\n");
        file_put_contents($root . '/nested/more.md', "more\n");
        file_put_contents($root . '/.git', 'not a repo');

        $result = $this->make(ListFilesTool::class)->call('list_files', ['book' => 'loose']);

        $this->assertNull($result['commit']);
        $files = array_column($result['entries'], 'file');
        sort($files);
        $this->assertSame(['nested/more.md', 'note.md'], $files);
    }

    public function testCallWithMissingBookRejects(): void
    {
        $this->expectException(ValidateFailedException::class);
        $this->expectExceptionMessage('Book does not exist');

        $this->make(ListFilesTool::class)->call('list_files', ['book' => 'missing']);
    }

    public function testCallRejectsInvalidBookSlug(): void
    {
        $this->expectException(ValidateFailedException::class);
        $this->expectExceptionMessage('Invalid book');

        $this->make(ListFilesTool::class)->call('list_files', ['book' => 'bad/name']);
    }

    public function testCallRejectsWithoutAcl(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $this->credential->payload['books'] = [];

        $this->expectException(ValidateFailedException::class);
        $this->expectExceptionMessage('Book not allowed');

        $this->make(ListFilesTool::class)->call('list_files', ['book' => 'demo']);
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
