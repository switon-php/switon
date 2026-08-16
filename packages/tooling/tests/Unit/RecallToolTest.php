<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Thread;
use Switon\Tooling\ArgumentBinder;
use Switon\Tooling\Exception;
use Switon\Tooling\Tests\Fixtures\ToolTestAgent;
use Switon\Tooling\Tests\ToolTestCase;
use Switon\Tooling\Tool\RecallTool;

use function file_put_contents;

#[CoversClass(RecallTool::class)]
class RecallToolTest extends ToolTestCase
{
    public function testCallSearchesThreadFile(): void
    {
        $dir = $this->threadDir();
        file_put_contents($dir . '/transcript.md', "hello\ngap\nneedle\nfound\n");

        $result = $this->callTool($this->make(RecallTool::class), 'recall', [
            'file' => 'transcript.md',
            'pattern' => 'needle',
            '-A' => 1,
            '-B' => 1,
        ], datadir: $dir);

        $this->assertTrue($result['matches']);
        $this->assertStringContainsString('needle', $result['content']);
        $this->assertMatchesRegularExpression('/^L\d+: /m', $result['content']);
        $this->assertStringNotContainsString('hello', $result['content']);
    }

    public function testCallRejectsPathOutsideThread(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('must be a relative path');

        $this->callTool($this->make(RecallTool::class), 'recall', [
            'file' => '../secret',
            'pattern' => 'x',
        ]);
    }

    public function testCallRejectsAbsolutePath(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File "/data/runner/thread/out/bash-3.txt" must be a relative path');

        $this->callTool($this->make(RecallTool::class), 'recall', [
            'file' => '/data/runner/thread/out/bash-3.txt',
            'pattern' => 'x',
        ]);
    }

    public function testCallReadsWholeTextFileWithoutPattern(): void
    {
        $dir = $this->threadDir();
        file_put_contents($dir . '/note.txt', "alpha\nbeta\n");

        $result = $this->callTool($this->make(RecallTool::class), 'recall', [
            'file' => 'note.txt',
        ], datadir: $dir);

        $this->assertSame("alpha\nbeta\n", $result);

        $prefixed = $this->callTool($this->make(RecallTool::class), 'recall', [
            'file' => '@thread/note.txt',
        ], datadir: $dir);

        $this->assertSame("alpha\nbeta\n", $prefixed);
    }

    public function testCallDefaultsPathToTranscript(): void
    {
        $dir = $this->threadDir();
        file_put_contents($dir . '/transcript.md', "user: hello\n");

        $tool = $this->make(RecallTool::class);
        $arguments = (new ArgumentBinder())->bind($tool->schema('recall'), []);
        $result = $this->callTool($tool, 'recall', $arguments, datadir: $dir);

        $this->assertSame("user: hello\n", $result);
    }

    public function testMissingPathErrors(): void
    {
        $dir = $this->threadDir();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File not found: "note.txt".');

        $this->callTool($this->make(RecallTool::class), 'recall', [
            'file' => 'note.txt',
        ], datadir: $dir);
    }

    public function testCallUsesTheTranscriptWhenTheFileIsEmpty(): void
    {
        $dir = $this->threadDir();
        file_put_contents($dir . '/transcript.md', "body\n");

        $result = $this->callTool($this->make(RecallTool::class), 'recall', [
            'file' => '',
        ], datadir: $dir);

        $this->assertSame("body\n", $result);
    }

    public function testCallRejectsAThreadWithoutADirectory(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File not found: "note.txt".');

        $this->callWithAgent(
            $this->make(RecallTool::class),
            'recall',
            ['file' => 'note.txt'],
            new ToolTestAgent(Thread::of('memory')),
        );
    }

    public function testCallSearchHonorsFlagsAndDropsEarlierMatches(): void
    {
        $dir = $this->threadDir();
        file_put_contents($dir . '/notes.txt', "Alpha\nbeta\nALPHA\n");

        $result = $this->callTool($this->make(RecallTool::class), 'recall', [
            'file' => 'notes.txt',
            'pattern' => 'alpha',
            'head_limit' => 1,
            'offset' => 1,
            '-i' => true,
            'multiline' => true,
        ], datadir: $dir);

        $this->assertTrue($result['matches']);
        $this->assertStringContainsString('ALPHA', $result['content']);
        $this->assertStringNotContainsString('Alpha', $result['content']);
    }

    public function testIsReadonly(): void
    {
        $this->assertTrue($this->make(RecallTool::class)->isReadonly('recall', [
            'file' => 'transcript.md',
            'pattern' => 'x',
        ]));
    }
}
