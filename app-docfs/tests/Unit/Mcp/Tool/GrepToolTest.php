<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Mcp\Tool\GrepTool;
use App\Mcp\Tool\MkbookTool;
use App\Mcp\Tool\WriteTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\PathAliasInterface;
use Switon\Testing\TestCase;

#[CoversClass(GrepTool::class)]
final class GrepToolTest extends TestCase
{
    private string $booksRoot;

    /** @var object{payload: array<string, mixed>}&CredentialInterface */
    private object $credential;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->booksRoot = sys_get_temp_dir() . '/docfs_grep_' . bin2hex(random_bytes(8));
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

    public function testSchemaDescribesRootGlob(): void
    {
        $schema = $this->make(GrepTool::class)->schema('grep');

        $this->assertStringContainsString('concepts-*.md', $schema->definition['inputSchema']['properties']['file']['description']);
    }

    public function testCallRejectsEmptyPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pattern is required');

        $this->make(GrepTool::class)->call('grep', [
            'book' => 'demo',
            'pattern' => '',
        ]);
    }

    public function testCallTruncatesAtTwoHundredHits(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'overview.md',
            'content' => str_repeat("hit\n", 201),
            'git_message' => 'Add hits',
        ]);
        file_put_contents($this->booksRoot . '/demo/bad name.md', "hit\n");

        $result = $this->make(GrepTool::class)->call('grep', [
            'book' => 'demo',
            'pattern' => 'hit',
        ]);

        $this->assertTrue($result['truncated']);
        $this->assertCount(200, $result['matches'][0]['hits']);
        $this->assertSame(['overview.md'], array_column($result['matches'], 'file'));
    }

    public function testCallGroupsHitsByFile(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'overview.md',
            'content' => "alpha\nbeta needle\ngamma needle\n",
            'git_message' => 'Add overview',
        ]);
        mkdir($this->booksRoot . '/demo/assets', 0775);
        file_put_contents($this->booksRoot . '/demo/assets/x.bin', "needle in asset\n");

        $result = $this->make(GrepTool::class)->call('grep', [
            'book' => 'demo',
            'pattern' => 'needle',
        ]);

        $this->assertCount(1, $result['matches']);
        $this->assertSame('overview.md', $result['matches'][0]['file']);
        $this->assertArrayHasKey('sha', $result['matches'][0]);
        $this->assertSame([
            ['line' => 2, 'text' => 'beta needle'],
            ['line' => 3, 'text' => 'gamma needle'],
        ], $result['matches'][0]['hits']);
        $this->assertArrayNotHasKey('commit', $result);
        $this->assertFalse($result['truncated']);
    }

    public function testCallScopesToOneFile(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'a.md',
            'content' => "hit\n",
            'git_message' => 'Add a',
        ]);
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'b.md',
            'content' => "hit\n",
            'git_message' => 'Add b',
        ]);

        $result = $this->make(GrepTool::class)->call('grep', [
            'book' => 'demo',
            'pattern' => 'hit',
            'file' => 'a.md',
        ]);

        $this->assertSame('a.md', $result['file']);
        $this->assertCount(1, $result['matches']);
        $this->assertSame('a.md', $result['matches'][0]['file']);
        $this->assertSame([['line' => 1, 'text' => 'hit']], $result['matches'][0]['hits']);
    }

    public function testCallScopesByRootGlob(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        foreach (['concepts-a.md' => 'hit a', 'concepts-b.md' => 'hit b', 'other.md' => 'hit other'] as $file => $text) {
            $this->make(WriteTool::class)->call('write', [
                'book' => 'demo',
                'file' => $file,
                'content' => $text . "\n",
                'git_message' => 'Add ' . $file,
            ]);
        }

        $result = $this->make(GrepTool::class)->call('grep', [
            'book' => 'demo',
            'pattern' => 'hit',
            'file' => 'concepts-*.md',
        ]);

        $this->assertSame('concepts-*.md', $result['file']);
        $this->assertSame(['concepts-a.md', 'concepts-b.md'], array_column($result['matches'], 'file'));
    }

    public function testCallRejectsDirectoryGlob(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        foreach (['assets/*.md', 'nested/a.md', '**.md'] as $file) {
            try {
                $this->make(GrepTool::class)->call('grep', [
                    'book' => 'demo',
                    'pattern' => 'hit',
                    'file' => $file,
                ]);
                $this->fail($file);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid file name', $e->getMessage());
            }
        }
    }

    public function testCallGlobMissRaisesNotFound(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('not found');

        $this->make(GrepTool::class)->call('grep', [
            'book' => 'demo',
            'pattern' => 'hit',
            'file' => 'missing-*.md',
        ]);
    }

    public function testCallRejectsInvalidPattern(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid pattern');

        $this->make(GrepTool::class)->call('grep', [
            'book' => 'demo',
            'pattern' => '(unclosed',
        ]);
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
