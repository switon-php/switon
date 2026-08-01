<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Tool;

use App\Docfs\CredentialInterface;
use App\Docfs\Git;
use App\Mcp\Tool\MkbookTool;
use App\Mcp\Tool\PatchTool;
use App\Mcp\Tool\WriteTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Mcp\ToolResult;
use Switon\Testing\TestCase;

#[CoversClass(PatchTool::class)]
final class PatchToolTest extends TestCase
{
    private string $booksRoot;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->booksRoot = sys_get_temp_dir() . '/docfs_patch_' . bin2hex(random_bytes(8));
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

    public function testSchemaRequiresOps(): void
    {
        $schema = $this->make(PatchTool::class)->schema('patch');

        $this->assertSame(['book', 'file', 'sha', 'git_message', 'ops'], $schema->definition['inputSchema']['required']);
    }

    public function testOpsApplySequentiallyInOneCommit(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $body = "alpha\nbeta\ngamma\n";
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'note.md',
            'content' => $body,
            'git_message' => 'add note',
        ]);

        $result = $this->make(PatchTool::class)->call('patch', [
            'book' => 'demo',
            'file' => 'note.md',
            'sha' => $this->blobSha($body),
            'git_message' => 'multi fix',
            'ops' => [
                ['op' => 'replace_span', 'old' => 'alpha', 'new' => 'ALPHA'],
                ['op' => 'replace_span', 'old' => 'gamma', 'new' => 'GAMMA'],
            ],
        ]);

        $fs = $this->container->get(FilesystemInterface::class);
        $this->assertSame("ALPHA\nbeta\nGAMMA\n", $fs->read('@books/demo/note.md'));
        $this->assertSame($this->blobSha("ALPHA\nbeta\nGAMMA\n"), $result['sha']);
    }

    public function testOpsRejectBoolScalars(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $body = "alpha\n";
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'note.md',
            'content' => $body,
            'git_message' => 'add note',
        ]);
        $sha = $this->blobSha($body);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ops[0].start_line must be an integer');

        $this->make(PatchTool::class)->call('patch', [
            'book' => 'demo',
            'file' => 'note.md',
            'sha' => $sha,
            'git_message' => 'bad op',
            'ops' => [
                ['op' => 'replace_lines', 'start_line' => false, 'end_line' => 1, 'content' => 'x'],
            ],
        ]);
    }

    public function testOpsAcceptIntegerStrings(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $body = "alpha\nbeta\n";
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'note.md',
            'content' => $body,
            'git_message' => 'add note',
        ]);

        $this->make(PatchTool::class)->call('patch', [
            'book' => 'demo',
            'file' => 'note.md',
            'sha' => $this->blobSha($body),
            'git_message' => 'line fix',
            'ops' => [
                ['op' => 'replace_span', 'old' => 'alpha', 'new' => 'ALPHA', 'count' => '1'],
                ['op' => 'replace_lines', 'start_line' => '2', 'end_line' => '2', 'content' => "BETA\n"],
            ],
        ]);

        $fs = $this->container->get(FilesystemInterface::class);
        $this->assertStringContainsString('ALPHA', $fs->read('@books/demo/note.md'));
        $this->assertStringContainsString('BETA', $fs->read('@books/demo/note.md'));
    }

    public function testReplaceSpanCountZeroReplacesAll(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $body = "aa aa\n";
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'note.md',
            'content' => $body,
            'git_message' => 'add note',
        ]);

        $this->make(PatchTool::class)->call('patch', [
            'book' => 'demo',
            'file' => 'note.md',
            'sha' => $this->blobSha($body),
            'git_message' => 'replace all',
            'ops' => [
                ['op' => 'replace_span', 'old' => 'aa', 'new' => 'bb', 'count' => 0],
            ],
        ]);

        $this->assertSame("bb bb\n", $this->container->get(FilesystemInterface::class)->read('@books/demo/note.md'));
    }

    public function testCallShaMismatchReturnsCurrentSha(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $body = "alpha\n";
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'note.md',
            'content' => $body,
            'git_message' => 'add note',
        ]);

        $result = $this->make(PatchTool::class)->call('patch', [
            'book' => 'demo',
            'file' => 'note.md',
            'sha' => str_repeat('0', 7),
            'git_message' => 'patch',
            'ops' => [
                ['op' => 'replace_span', 'old' => 'alpha', 'new' => 'beta'],
            ],
        ]);

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertSame($this->blobSha($body), $result->content['sha']);
    }

    public function testCallRejectsUnknownOp(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $body = "alpha\n";
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'note.md',
            'content' => $body,
            'git_message' => 'add note',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown op at ops[0]');

        $this->make(PatchTool::class)->call('patch', [
            'book' => 'demo',
            'file' => 'note.md',
            'sha' => $this->blobSha($body),
            'git_message' => 'patch',
            'ops' => [
                ['op' => 'delete'],
            ],
        ]);
    }

    public function testCallRejectsEmptyOld(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $body = "alpha\n";
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'note.md',
            'content' => $body,
            'git_message' => 'add note',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('old must not be empty');

        $this->make(PatchTool::class)->call('patch', [
            'book' => 'demo',
            'file' => 'note.md',
            'sha' => $this->blobSha($body),
            'git_message' => 'patch',
            'ops' => [
                ['op' => 'replace_span', 'old' => '', 'new' => 'x'],
            ],
        ]);
    }

    public function testCallRejectsMissingOld(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $body = "alpha\n";
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'note.md',
            'content' => $body,
            'git_message' => 'add note',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('old not found');

        $this->make(PatchTool::class)->call('patch', [
            'book' => 'demo',
            'file' => 'note.md',
            'sha' => $this->blobSha($body),
            'git_message' => 'patch',
            'ops' => [
                ['op' => 'replace_span', 'old' => 'missing', 'new' => 'x'],
            ],
        ]);
    }

    public function testCallRejectsInvalidLineRange(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);
        $body = "alpha\n";
        $this->make(WriteTool::class)->call('write', [
            'book' => 'demo',
            'file' => 'note.md',
            'content' => $body,
            'git_message' => 'add note',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid line range');

        $this->make(PatchTool::class)->call('patch', [
            'book' => 'demo',
            'file' => 'note.md',
            'sha' => $this->blobSha($body),
            'git_message' => 'patch',
            'ops' => [
                ['op' => 'replace_lines', 'start_line' => 2, 'end_line' => 1, 'content' => 'x'],
            ],
        ]);
    }

    public function testCallMissingFileRaisesNotFound(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('not found');

        $this->make(PatchTool::class)->call('patch', [
            'book' => 'demo',
            'file' => 'missing.md',
            'sha' => str_repeat('0', 7),
            'git_message' => 'patch',
            'ops' => [
                ['op' => 'replace_span', 'old' => 'a', 'new' => 'b'],
            ],
        ]);
    }

    public function testCallRejectsEmptyOps(): void
    {
        $this->make(MkbookTool::class)->call('mkbook', ['book' => 'demo']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ops must be a non-empty list');

        $this->make(PatchTool::class)->call('patch', [
            'book' => 'demo',
            'file' => 'note.md',
            'sha' => str_repeat('0', 7),
            'git_message' => 'patch',
            'ops' => [],
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
