<?php

declare(strict_types=1);

namespace App\Tests\Unit\Docfs;

use App\Docfs\AssetStore;
use App\Docfs\CredentialInterface;
use App\Docfs\Git;
use App\Docfs\ShaMismatchException;
use App\Mcp\Tool\MkbookTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Testing\TestCase;

use function basename;

#[CoversClass(AssetStore::class)]
final class AssetStoreTest extends TestCase
{
    private string $booksRoot;

    /** @var object{payload: array<string, mixed>}&CredentialInterface */
    private object $credential;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->booksRoot = sys_get_temp_dir() . '/docfs_asset_' . bin2hex(random_bytes(8));
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

    public function testPutStoresUuidExtAndReturnsMetaWithoutContent(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $result = $this->make(AssetStore::class)->put('demo', 'diagram.png', "\x89PNG\r\n", 'Add diagram');

        $this->assertMatchesRegularExpression(
            '#^assets/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.png$#i',
            $result['file']
        );
        $this->assertSame('diagram.png', $result['original']);
        $this->assertSame(6, $result['size']);
        $this->assertSame($this->blobSha("\x89PNG\r\n"), $result['sha']);
        $this->assertArrayNotHasKey('content', $result);
        $this->assertSame(
            "\x89PNG\r\n",
            $this->container->get(FilesystemInterface::class)->read('@books/demo/' . $result['file'])
        );
    }

    public function testGetReturnsRawBytes(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $put = $this->make(AssetStore::class)->put('demo', 'note.txt', "hello\n", 'Add note');
        $stored = basename($put['file']);

        $got = $this->make(AssetStore::class)->get('demo', $stored);

        $this->assertSame($put['file'], $got['file']);
        $this->assertSame("hello\n", $got['content']);
        $this->assertSame($put['sha'], $got['sha']);
    }

    public function testRmRequiresMatchingSha(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $put = $this->make(AssetStore::class)->put('demo', 'note.txt', "hello\n", 'Add note');
        $stored = basename($put['file']);

        try {
            $this->make(AssetStore::class)->rm('demo', $stored, 'deadbee', 'Remove note');
            $this->fail('Expected ShaMismatchException');
        } catch (ShaMismatchException $e) {
            $this->assertSame($put['sha'], $e->sha);
            $this->assertSame($put['file'], $e->docFile);
        }

        $deleted = $this->make(AssetStore::class)->rm('demo', $stored, $put['sha'], 'Remove note');
        $this->assertTrue($deleted['deleted']);
        $this->assertFalse(
            $this->container->get(FilesystemInterface::class)->exists('@books/demo/' . $put['file'])
        );
    }

    public function testPutRejectsMarkdownExtension(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $this->expectException(InvalidArgumentException::class);
        $this->make(AssetStore::class)->put('demo', 'page.md', "# x\n", 'Nope');
    }

    private function blobSha(string $raw): string
    {
        return $this->container->get(Git::class)->abbrev(
            $this->container->get(Git::class)->hashObject($raw)
        );
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
