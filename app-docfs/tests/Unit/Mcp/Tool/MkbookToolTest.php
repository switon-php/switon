<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Mcp\Tool\MkbookTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Testing\TestCase;
use Switon\Validating\Exception\ValidateFailedException;

#[CoversClass(MkbookTool::class)]
final class MkbookToolTest extends TestCase
{
    private const EMPTY_BLOB_SHA = 'e69de29';

    private string $booksRoot;

    /** @var object{payload: array<string, mixed>}&CredentialInterface */
    private object $credential;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->booksRoot = sys_get_temp_dir() . '/docfs_mkbook_' . bin2hex(random_bytes(8));
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

    public function testSchemaDescribesGitMaintainedBook(): void
    {
        $tool = $this->make(MkbookTool::class);
        $schema = $tool->schema('mkbook');

        $this->assertSame('Create a git-maintained book.', $schema->definition['description']);
        $this->assertSame(['book'], $schema->definition['inputSchema']['required']);
        $this->assertArrayHasKey('book', $schema->definition['inputSchema']['properties']);
    }

    public function testCallCreatesGitBookWithRequiredFiles(): void
    {
        $tool = $this->make(MkbookTool::class);
        $fs = $this->container->get(FilesystemInterface::class);

        $result = $tool->call('mkbook', ['book' => 'demo']);

        $this->assertSame('demo', $result['book']);
        $this->assertCount(2, $result['entries']);
        $this->assertSame('index.md', $result['entries'][0]['file']);
        $this->assertSame('AGENT.md', $result['entries'][1]['file']);
        $this->assertSame(0, $result['entries'][0]['size']);
        $this->assertSame(0, $result['entries'][1]['size']);
        $this->assertSame(self::EMPTY_BLOB_SHA, $result['entries'][0]['sha']);
        $this->assertSame(self::EMPTY_BLOB_SHA, $result['entries'][1]['sha']);

        $this->assertTrue($fs->isDir('@books/demo'));
        $this->assertTrue($fs->exists('@books/demo/index.md'));
        $this->assertTrue($fs->exists('@books/demo/AGENT.md'));
        $this->assertSame('', $fs->read('@books/demo/index.md'));
        $this->assertSame('', $fs->read('@books/demo/AGENT.md'));
        $this->assertDirectoryExists($this->booksRoot . '/demo/.git');

        $subject = trim((string) shell_exec(
            'git -C ' . escapeshellarg($this->booksRoot . '/demo') . ' log -1 --format=%s'
        ));
        $this->assertSame('Initialize book with index.md and AGENT.md', $subject);
    }

    public function testCallRejectsInvalidBookSlug(): void
    {
        $tool = $this->make(MkbookTool::class);

        $this->expectException(ValidateFailedException::class);
        $this->expectExceptionMessage('Invalid book');

        $tool->call('mkbook', ['book' => 'bad/name']);
    }

    public function testCallRejectsWithoutWildcardWrite(): void
    {
        $this->credential->payload['books'] = ['demo' => CredentialInterface::PERM_WRITE];
        $tool = $this->make(MkbookTool::class);

        $this->expectException(ValidateFailedException::class);
        $this->expectExceptionMessage('forbidden');

        $tool->call('mkbook', ['book' => 'demo']);
    }

    public function testCallRejectsExistingBook(): void
    {
        mkdir($this->booksRoot . '/demo', 0775);
        $tool = $this->make(MkbookTool::class);

        $this->expectException(ValidateFailedException::class);
        $this->expectExceptionMessage('already exists');

        $tool->call('mkbook', ['book' => 'demo']);
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
