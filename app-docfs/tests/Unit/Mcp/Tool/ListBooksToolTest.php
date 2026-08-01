<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Mcp\Tool\ListBooksTool;
use App\Mcp\Tool\MkbookTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\PathAliasInterface;
use Switon\Testing\TestCase;

#[CoversClass(ListBooksTool::class)]
final class ListBooksToolTest extends TestCase
{
    private string $booksRoot;

    /** @var object{payload: array<string, mixed>}&CredentialInterface */
    private object $credential;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->booksRoot = sys_get_temp_dir() . '/docfs_list_books_' . bin2hex(random_bytes(8));
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

    public function testSchemaHasNoArgs(): void
    {
        $tool = $this->make(ListBooksTool::class);
        $schema = $tool->schema('list_books');

        $this->assertSame('List book names on the bookshelf.', $schema->definition['description']);
        $this->assertSame([], $schema->definition['inputSchema']['properties']);
    }

    public function testCallWithWildcardListsAllDirs(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'other']);
        file_put_contents($this->booksRoot . '/notes.txt', 'ignore');

        $result = $this->make(ListBooksTool::class)->call('list_books', []);

        $names = array_column($result['entries'], 'book');
        sort($names);
        $this->assertSame(['demo', 'other'], $names);
        $this->assertSame(['book'], array_keys($result['entries'][0]));
    }

    public function testCallWithExplicitAclReturnsKeysWithoutExistenceCheck(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $this->credential->payload['books'] = [
            'demo' => CredentialInterface::PERM_READ,
            'ghost' => CredentialInterface::PERM_WRITE,
        ];

        $result = $this->make(ListBooksTool::class)->call('list_books', []);

        $names = array_column($result['entries'], 'book');
        $this->assertSame(['demo', 'ghost'], $names);
    }

    public function testCallReturnsEmptyWhenNoBooks(): void
    {
        $result = $this->make(ListBooksTool::class)->call('list_books', []);

        $this->assertSame(['entries' => []], $result);
    }

    public function testCallWithEmptyAclReturnsEmpty(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $this->credential->payload['books'] = [];

        $result = $this->make(ListBooksTool::class)->call('list_books', []);

        $this->assertSame(['entries' => []], $result);
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
