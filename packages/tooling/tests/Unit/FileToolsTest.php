<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\Path;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Ai\Exception\ToolInvalidArgumentException;
use Switon\Tooling\ArgumentBinder;
use Switon\Tooling\Exception;
use Switon\Tooling\Exception\ArgumentBindException;
use Switon\Tooling\Tests\ToolTestCase;
use Switon\Tooling\Tool\EditsTool;
use Switon\Tooling\Tool\EditTool;
use Switon\Tooling\Tool\GlobTool;
use Switon\Tooling\Tool\LsTool;
use Switon\Tooling\Tool\ReadTool;
use Switon\Tooling\Tool\WriteTool;

#[CoversClass(ReadTool::class)]
#[CoversClass(WriteTool::class)]
#[CoversClass(EditTool::class)]
#[CoversClass(EditsTool::class)]
#[CoversClass(GlobTool::class)]
#[CoversClass(LsTool::class)]
class FileToolsTest extends ToolTestCase
{
    public function testFileWriteCreatesParentDirectory(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/nested/plans/feature.md';
        $write = $this->make(WriteTool::class);
        $result = $this->callTool($write, 'write', [
            'path' => $file,
            'content' => "# Plan\n",
        ]);

        $this->assertArrayNotHasKey('path', $result);
        $this->assertSame(7, $result['bytes']);
        $this->assertDirectoryExists($dir . '/nested/plans');
        $this->assertSame("# Plan\n", file_get_contents($file));
    }

    public function testFileWriteSchemaIsShort(): void
    {
        $schema = $this->make(WriteTool::class)->schema('write');

        $this->assertSame('Write a file to the local filesystem. On success, may include a `lint` report for the path when a driver exists.', $schema->definition['description']);
        $this->assertSame(['path', 'content'], $schema->definition['inputSchema']['required']);
        $this->assertSame('Absolute path.', $schema->definition['inputSchema']['properties']['path']['description']);
        $this->assertSame('File content.', $schema->definition['inputSchema']['properties']['content']['description']);
        $this->assertArrayNotHasKey('default', $schema->definition['inputSchema']['properties']['content']);
    }

    public function testFileWritePropagatesFilesystemWriteFailure(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/fail.txt';

        $fs = $this->createMock(FilesystemInterface::class);
        $fs->method('resolve')->willReturnArgument(0);
        $fs->expects($this->once())
            ->method('write')
            ->willReturnCallback(static function (): void {
                RuntimeException::raise('Failed to write to file "{file}".', ['file' => 'x']);
            });

        $write = $this->make(WriteTool::class, ['filesystem' => $fs]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to write to file');

        $this->callTool($write, 'write', [
            'path' => $file,
            'content' => 'x',
        ]);
    }

    public function testReadWriteAndListDir(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/note.txt';
        $write = $this->make(WriteTool::class);
        $writeResult = $this->callTool($write, 'write', [
            'path' => $file,
            'content' => "line1\nline2",
        ]);
        $this->assertSame(11, $writeResult['bytes']);

        $read = $this->make(ReadTool::class);
        $readResult = $this->callTool($read, 'read', ['path' => $file]);
        $this->assertSame("L1: line1\nL2: line2", $readResult['content']);

        $list = $this->make(LsTool::class);
        $listResult = $this->callTool($list, 'ls', ['path' => $dir]);
        $this->assertSame('note.txt', $listResult['files'][0]['name']);
        $this->assertSame([], $listResult['dirs']);
    }

    public function testReadFileRejectsMissingPath(): void
    {
        $read = $this->make(ReadTool::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File not found');

        $this->callTool($read, 'read', ['path' => '/tmp/switon-agent-missing-' . uniqid('', true)]);
    }

    public function testReadResolvesThreadPrefixOutsideWorkspace(): void
    {
        $workspace = $this->tempDir();
        $thread = $this->tempDir();
        file_put_contents($thread . '/note.txt', "from-thread\n");
        file_put_contents($workspace . '/note.txt', "from-workspace\n");

        $read = $this->make(ReadTool::class);
        $result = $this->callTool($read, 'read', [
            'path' => '@thread/note.txt',
        ], datadir: $thread, workspace: $workspace);

        $this->assertSame('L1: from-thread', $result['content']);
    }

    public function testReadExpandsTilde(): void
    {
        $home = $this->tempDir();
        file_put_contents($home . '/note.txt', "from-home\n");
        $previous = getenv('HOME');
        putenv('HOME=' . $home);

        try {
            $read = $this->make(ReadTool::class);
            $result = $this->callTool($read, 'read', ['path' => '~/note.txt']);
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }

        $this->assertSame('L1: from-home', $result['content']);
    }

    public function testReadRejectsAlias(): void
    {
        $dir = $this->tempDir();
        $this->container->get(PathAliasInterface::class)->set('@toolread', $dir);
        $read = $this->make(ReadTool::class);

        $this->expectException(\Switon\Core\Exception::class);
        $this->expectExceptionMessage('Path must be absolute');

        $this->callTool($read, 'read', ['path' => '@toolread/note.txt']);
    }

    public function testReadRejectsRelativePath(): void
    {
        $read = $this->make(ReadTool::class);

        $this->expectException(\Switon\Core\Exception::class);
        $this->expectExceptionMessage('Path must be absolute');

        $this->callTool($read, 'read', ['path' => 'note.txt']);
    }

    public function testReadFileWithOffsetAndLimit(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/lines.txt';
        file_put_contents($file, "one\ntwo\nthree\nfour\n");

        $read = $this->make(ReadTool::class);
        $result = $this->callTool($read, 'read', [
            'path' => $file,
            'offset' => 2,
            'limit' => 2,
        ]);

        $this->assertSame("L2: two\nL3: three", $result['content']);
        $this->assertArrayNotHasKey('offset', $result);
        $this->assertArrayNotHasKey('limit', $result);
        $this->assertSame(4, $result['total_lines']);
        $this->assertSame(['offset' => 4], $result['next']);
    }

    public function testReadFileNegativeOffsetCountsFromEnd(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/lines.txt';
        file_put_contents($file, "one\ntwo\nthree\nfour\n");

        $read = $this->make(ReadTool::class);
        $result = $this->callTool($read, 'read', ['path' => $file, 'offset' => -1, 'limit' => 1]);

        $this->assertSame('L4: four', $result['content']);
        $this->assertArrayNotHasKey('offset', $result);
    }

    public function testReadFileRejectsLimitBelowOne(): void
    {
        $this->expectException(ArgumentBindException::class);
        $this->expectExceptionMessage('limit below 1');

        (new ArgumentBinder())->bind($this->make(ReadTool::class)->schema('read'), [
            'path' => '/tmp/lines.txt',
            'limit' => 0,
        ]);
    }

    public function testReadFileRejectsOffsetExceedingLineCount(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/lines.txt';
        file_put_contents($file, "one\ntwo\n");

        $read = $this->make(ReadTool::class);

        $this->expectException(ToolInvalidArgumentException::class);
        $this->expectExceptionMessage('offset value invalid');

        $this->callTool($read, 'read', ['path' => $file, 'offset' => 99]);
    }

    public function testFilePatchReplacesUniqueSubstring(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/patch.txt';
        file_put_contents($file, "alpha\nbeta\nalpha\n");

        $patch = $this->make(EditTool::class);
        $result = $this->callTool($patch, 'edit', [
            'path' => $file,
            'old' => "beta\n",
            'new' => "BETA\n",
        ]);

        $this->assertArrayNotHasKey('path', $result);
        $this->assertArrayNotHasKey('path', $result);
        $this->assertArrayNotHasKey('all', $result);
        $this->assertSame(1, $result['replacements']);
        $this->assertSame(2, $result['line']);
        $this->assertSame("alpha\nBETA\nalpha\n", file_get_contents($file));
    }

    public function testFilePatchRejectsAmbiguousSubstring(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/dup.txt';
        file_put_contents($file, 'foo bar foo');

        $patch = $this->make(EditTool::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('not unique');

        $this->callTool($patch, 'edit', [
            'path' => $file,
            'old' => 'foo',
            'new' => 'baz',
        ]);
    }

    public function testFilePatchReplaceAll(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/all.txt';
        file_put_contents($file, 'aa aa aa');

        $patch = $this->make(EditTool::class);
        $result = $this->callTool($patch, 'edit', [
            'path' => $file,
            'old' => 'aa',
            'new' => 'b',
            'all' => true,
        ]);

        $this->assertSame(3, $result['replacements']);
        $this->assertSame('b b b', file_get_contents($file));
    }

    public function testFilePatchMatchesLfOldStringAgainstCrlfFile(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/crlf.txt';
        file_put_contents($file, "alpha\r\nbeta\r\nalpha\r\n");

        $patch = $this->make(EditTool::class);
        $result = $this->callTool($patch, 'edit', [
            'path' => $file,
            'old' => "beta\n",
            'new' => "BETA\n",
        ]);

        $this->assertSame(1, $result['replacements']);
        $this->assertSame("alpha\r\nBETA\r\nalpha\r\n", file_get_contents($file));
    }

    public function testFilePatchMatchesLfOldStringAgainstCrFile(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/cr.txt';
        file_put_contents($file, "alpha\rbeta\ralpha\r");

        $result = $this->callTool($this->make(EditTool::class), 'edit', [
            'path' => $file,
            'old' => "beta\n",
            'new' => "BETA\n",
        ]);

        $this->assertSame(1, $result['replacements']);
        $this->assertSame("alpha\rBETA\ralpha\r", file_get_contents($file));
    }

    public function testFilePatchAcceptsCrlfOldStringAgainstLfFile(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/lf.txt';
        file_put_contents($file, "alpha\nbeta\nalpha\n");

        $result = $this->callTool($this->make(EditTool::class), 'edit', [
            'path' => $file,
            'old' => "beta\r\n",
            'new' => "BETA\r\n",
        ]);

        $this->assertSame(1, $result['replacements']);
        $this->assertSame("alpha\nBETA\nalpha\n", file_get_contents($file));
    }

    public function testFilePatchReplaceAllPreservesCrlf(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/all-crlf.txt';
        file_put_contents($file, "aa\r\naa\r\n");

        $result = $this->callTool($this->make(EditTool::class), 'edit', [
            'path' => $file,
            'old' => 'aa',
            'new' => 'b',
            'all' => true,
        ]);

        $this->assertSame(2, $result['replacements']);
        $this->assertSame("b\r\nb\r\n", file_get_contents($file));
    }

    public function testFilePatchSingleLinePreservesMixedEol(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/mixed.txt';
        file_put_contents($file, "a\r\nb\nc\r\n");

        $result = $this->callTool($this->make(EditTool::class), 'edit', [
            'path' => $file,
            'old' => 'b',
            'new' => 'B',
        ]);

        $this->assertSame(1, $result['replacements']);
        $this->assertSame("a\r\nB\nc\r\n", file_get_contents($file));
    }

    public function testFilePatchFallsBackWhenPrimaryEolMissesMatchRegion(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/fallback.txt';
        // Primary EOL is CRLF (first break), but the match span uses LF.
        file_put_contents($file, "head\r\nalpha\nbeta\n");

        $result = $this->callTool($this->make(EditTool::class), 'edit', [
            'path' => $file,
            'old' => "alpha\nbeta\n",
            'new' => "ALPHA\nBETA\n",
        ]);

        $this->assertSame(1, $result['replacements']);
        $this->assertSame("head\r\nALPHA\nBETA\n", file_get_contents($file));
    }

    public function testFilePatchRejectsMissingOldString(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/miss.txt';
        file_put_contents($file, "alpha\n");

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('old not found');

        $this->callTool($this->make(EditTool::class), 'edit', [
            'path' => $file,
            'old' => 'nope',
            'new' => 'x',
        ]);
    }

    public function testFilePatchRejectsMissingPath(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File not found');

        $this->callTool($this->make(EditTool::class), 'edit', [
            'path' => '/tmp/switon-agent-patch-missing-' . uniqid('', true),
            'old' => 'a',
            'new' => 'b',
        ]);
    }

    public function testFilePatchSchemaUsesShortParamNames(): void
    {
        $schema = $this->make(EditTool::class)->schema('edit');

        $this->assertSame('Replace text in a file. On success, may include a `lint` report for the path when a driver exists.', $schema->definition['description']);
        $this->assertSame(['path', 'old', 'new'], $schema->definition['inputSchema']['required']);
        $this->assertArrayHasKey('all', $schema->definition['inputSchema']['properties']);
        $this->assertFalse($schema->definition['inputSchema']['properties']['all']['default']);
        $this->assertArrayNotHasKey('old_string', $schema->definition['inputSchema']['properties']);
    }

    public function testEditsSchemaRequiresAtLeastOneEdit(): void
    {
        $schema = $this->make(EditsTool::class)->schema('edits');

        $this->assertSame(1, $schema->definition['inputSchema']['properties']['edits']['minItems']);
        $this->assertSame(['edits'], $schema->definition['inputSchema']['required']);
        $this->assertArrayHasKey('path', $schema->definition['inputSchema']['properties']);
        $this->assertSame(
            ['old', 'new'],
            $schema->definition['inputSchema']['properties']['edits']['items']['required'],
        );
    }

    public function testEditsInheritsTopLevelPathWhenItemOmitsPath(): void
    {
        $dir = $this->tempDir();
        $file = Path::absolute($dir . '/same.txt');
        file_put_contents($file, "one\ntwo\nthree\n");

        $result = $this->callTool($this->make(EditsTool::class), 'edits', [
            'path' => $file,
            'edits' => [
                ['old' => "two\n", 'new' => "TWO\n"],
                ['old' => "three\n", 'new' => "THREE\n"],
            ],
        ]);

        $this->assertSame([$file], $result['files']);
        $this->assertSame($file, $result['edits'][0]['path']);
        $this->assertSame($file, $result['edits'][1]['path']);
        $this->assertSame("one\nTWO\nTHREE\n", file_get_contents($file));
    }

    public function testEditsItemPathOverridesTopLevelPath(): void
    {
        $dir = $this->tempDir();
        $a = $dir . '/a.txt';
        $b = $dir . '/b.txt';
        file_put_contents($a, "keep\n");
        file_put_contents($b, "change-me\n");

        $this->callTool($this->make(EditsTool::class), 'edits', [
            'path' => $a,
            'edits' => [
                ['path' => $b, 'old' => "change-me\n", 'new' => "changed\n"],
            ],
        ]);

        $this->assertSame("keep\n", file_get_contents($a));
        $this->assertSame("changed\n", file_get_contents($b));
    }

    public function testFilePatchAllModeMissContinuesThenNotFound(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/all-miss.txt';
        file_put_contents($file, "alpha\n");

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('old not found');

        $this->callTool($this->make(EditTool::class), 'edit', [
            'path' => $file,
            'old' => 'nope',
            'new' => 'x',
            'all' => true,
        ]);
    }

    public function testFilePatchRejectsWhenReadFails(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/r.txt';
        file_put_contents($file, 'x');

        $fs = $this->createMock(FilesystemInterface::class);
        $fs->method('resolve')->willReturnArgument(0);
        $fs->method('isFile')->willReturn(true);
        $fs->expects($this->once())
            ->method('read')
            ->willReturnCallback(static function (): string {
                Exception::raise('Failed to read file.');
            });

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to read file');

        $this->callTool($this->make(EditTool::class, ['filesystem' => $fs]), 'edit', [
            'path' => $file,
            'old' => 'x',
            'new' => 'y',
        ]);
    }

    public function testFilePatchRejectsWhenWriteFails(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/w.txt';
        file_put_contents($file, 'x');

        $fs = $this->createMock(FilesystemInterface::class);
        $fs->method('resolve')->willReturnArgument(0);
        $fs->method('isFile')->willReturn(true);
        $fs->method('read')->willReturn('x');
        $fs->expects($this->once())
            ->method('write')
            ->willReturnCallback(static function (): void {
                Exception::raise('Failed to write file.');
            });

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to write file');

        $this->callTool($this->make(EditTool::class, ['filesystem' => $fs]), 'edit', [
            'path' => $file,
            'old' => 'x',
            'new' => 'y',
        ]);
    }

    public function testEditsAppliesAcrossFilesAtomically(): void
    {
        $dir = $this->tempDir();
        $a = Path::absolute($dir . '/a.txt');
        $b = Path::absolute($dir . '/b.txt');
        file_put_contents($a, "one\ntwo\n");
        file_put_contents($b, "three\n");

        $result = $this->callTool($this->make(EditsTool::class), 'edits', [
            'edits' => [
                ['path' => $a, 'old' => "two\n", 'new' => "TWO\n"],
                ['path' => $b, 'old' => "three\n", 'new' => "THREE\n"],
            ],
        ]);

        $this->assertSame([$a, $b], $result['files']);
        $this->assertCount(2, $result['edits']);
        $this->assertSame(2, $result['edits'][0]['line']);
        $this->assertSame(1, $result['edits'][1]['line']);
        $this->assertSame("one\nTWO\n", file_get_contents($a));
        $this->assertSame("THREE\n", file_get_contents($b));
    }

    public function testEditsSameFileSequentialSeesPriorResult(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/seq.txt';
        file_put_contents($file, 'alpha beta');

        $this->callTool($this->make(EditsTool::class), 'edits', [
            'edits' => [
                ['path' => $file, 'old' => 'alpha', 'new' => 'ALPHA'],
                ['path' => $file, 'old' => 'ALPHA beta', 'new' => 'done'],
            ],
        ]);

        $this->assertSame('done', file_get_contents($file));
    }

    public function testEditsWritesNothingWhenLaterStepFails(): void
    {
        $dir = $this->tempDir();
        $a = $dir . '/ok.txt';
        $b = $dir . '/bad.txt';
        file_put_contents($a, 'keep-me');
        file_put_contents($b, 'untouched');

        try {
            $this->callTool($this->make(EditsTool::class), 'edits', [
                'edits' => [
                    ['path' => $a, 'old' => 'keep-me', 'new' => 'changed'],
                    ['path' => $b, 'old' => 'missing', 'new' => 'x'],
                ],
            ]);
            $this->fail('expected Exception');
        } catch (Exception $e) {
            $this->assertSame('edits[1]: old not found.', $e->getMessage());
        }

        $this->assertSame('keep-me', file_get_contents($a));
        $this->assertSame('untouched', file_get_contents($b));
    }

    public function testEditsRejectsEmptyList(): void
    {
        $this->expectException(ArgumentBindException::class);

        $tool = $this->make(EditsTool::class);
        (new ArgumentBinder())->bind($tool->schema('edits'), ['edits' => []]);
    }

    public function testEditsRejectsItemMissingNewAsArgumentError(): void
    {
        $this->expectException(ArgumentBindException::class);
        $this->expectExceptionMessage('new');

        (new ArgumentBinder())->bind($this->make(EditsTool::class)->schema('edits'), [
            'edits' => [['path' => '/tmp/probe.txt', 'old' => 'hello']],
        ]);
    }

    public function testEditsRejectsNonStringReplacementAsArgumentError(): void
    {
        $this->expectException(ArgumentBindException::class);
        $this->expectExceptionMessage('type invalid');

        (new ArgumentBinder())->bind($this->make(EditsTool::class)->schema('edits'), [
            'edits' => [['path' => '/tmp/probe.txt', 'old' => 'hello', 'new' => 123]],
        ]);
    }

    public function testEditsRejectsStringAll(): void
    {
        $this->expectException(ArgumentBindException::class);
        $this->expectExceptionMessage('type invalid');

        (new ArgumentBinder())->bind($this->make(EditsTool::class)->schema('edits'), [
            'edits' => [['path' => '/tmp/probe.txt', 'old' => 'x', 'new' => 'y', 'all' => 'false']],
        ]);
    }

    public function testEditsKeepsEarlierWritesWhenLaterWriteFails(): void
    {
        $dir = $this->tempDir();
        $a = $dir . '/first.txt';
        $b = $dir . '/second.txt';
        file_put_contents($a, 'aaa');
        file_put_contents($b, 'bbb');

        $writes = 0;
        $fs = $this->createMock(FilesystemInterface::class);
        $fs->method('resolve')->willReturnArgument(0);
        $fs->method('isFile')->willReturn(true);
        $fs->expects($this->atLeastOnce())
            ->method('read')
            ->willReturnCallback(static fn (string $path): string => (string)file_get_contents($path));
        $fs->expects($this->atLeastOnce())
            ->method('write')
            ->willReturnCallback(static function (string $path, string $content) use (&$writes): void {
                $writes++;
                if ($writes === 2) {
                    Exception::raise('Failed to write file.');
                }
                if (file_put_contents($path, $content) === false) {
                    Exception::raise('Failed to write file.');
                }
            });

        try {
            $this->callTool($this->make(EditsTool::class, ['filesystem' => $fs]), 'edits', [
                'edits' => [
                    ['path' => $a, 'old' => 'aaa', 'new' => 'AAA'],
                    ['path' => $b, 'old' => 'bbb', 'new' => 'BBB'],
                ],
            ]);
            $this->fail('expected Exception');
        } catch (Exception $e) {
            $this->assertStringContainsString('Failed to write file', $e->getMessage());
        }

        $this->assertSame('AAA', file_get_contents($a));
        $this->assertSame('bbb', file_get_contents($b));
    }

    public function testEditAppendsLintForPhpFile(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/sample.php';
        file_put_contents($file, "<?php\n\nreturn 1;\n");

        $result = $this->callTool($this->make(EditTool::class), 'edit', [
            'path' => $file,
            'old' => 'return 1;',
            'new' => 'return 2;',
        ]);

        $this->assertSame(1, $result['replacements']);
        $this->assertArrayHasKey('lint', $result);
        $this->assertSame([['name' => 'php -l', 'ok' => true]], $result['lint']['tools']);
    }

    public function testEditOmitsLintForUnsupportedExtension(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/note.txt';
        file_put_contents($file, "hello\n");

        $result = $this->callTool($this->make(EditTool::class), 'edit', [
            'path' => $file,
            'old' => 'hello',
            'new' => 'hi',
        ]);

        $this->assertArrayNotHasKey('lint', $result);
    }

    public function testEditsAttachesLintListForMultiplePhpFiles(): void
    {
        $dir = $this->tempDir();
        $a = $dir . '/a.php';
        $b = $dir . '/b.php';
        file_put_contents($a, "<?php\n\nreturn 1;\n");
        file_put_contents($b, "<?php\n\nreturn 2;\n");

        $result = $this->callTool($this->make(EditsTool::class), 'edits', [
            'edits' => [
                ['path' => $a, 'old' => 'return 1;', 'new' => 'return 10;'],
                ['path' => $b, 'old' => 'return 2;', 'new' => 'return 20;'],
            ],
        ]);

        $this->assertArrayHasKey('lint', $result);
        $this->assertIsArray($result['lint']);
        $this->assertCount(2, $result['lint']);
        $this->assertSame([['name' => 'php -l', 'ok' => true]], $result['lint'][0]['tools']);
        $this->assertSame([['name' => 'php -l', 'ok' => true]], $result['lint'][1]['tools']);
    }

    public function testEditAttachesLintWithSyntaxError(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/broken.php';
        file_put_contents($file, "<?php\n\nreturn 1;\n");

        $result = $this->callTool($this->make(EditTool::class), 'edit', [
            'path' => $file,
            'old' => 'return 1;',
            'new' => 'echo (;',
        ]);

        $this->assertArrayHasKey('lint', $result);
        $this->assertSame([['name' => 'php -l', 'ok' => false]], $result['lint']['tools']);
        $this->assertNotEmpty($result['lint']['diagnostics']);
        $this->assertArrayHasKey('message', $result['lint']['diagnostics'][0]);
    }

    public function testGlobFindMatchesPattern(): void
    {
        if (!$this->executableOnPath('rg')) {
            $this->markTestSkipped('rg is not on PATH');
        }

        $dir = $this->tempDir();
        mkdir($dir . '/sub');
        file_put_contents($dir . '/a.php', '<?php');
        file_put_contents($dir . '/b.txt', 'x');
        file_put_contents($dir . '/sub/c.php', '<?php');

        $glob = $this->make(GlobTool::class);
        $result = $this->callTool($glob, 'glob', [
            'glob_pattern' => '*.php',
            'target_directory' => $dir,
        ]);

        $this->assertContains('a.php', $result['paths']);
        $this->assertNotContains('b.txt', $result['paths']);
        $this->assertNotContains($dir . '/a.php', $result['paths']);
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/switon-agent-file-tools-' . uniqid('', true);
        mkdir($dir);

        return $dir;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir() . '/switon-agent-file-tools-*') ?: [] as $dir) {
            $this->removeDirectory($dir);
        }

        parent::tearDown();
    }

    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
