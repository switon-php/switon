<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Exception\ToolInvalidArgumentException;
use Switon\Core\Path;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\ToolResult;
use Switon\Tooling\ArgumentBinder;
use Switon\Tooling\Exception;
use Switon\Tooling\Exception\ArgumentBindException;
use Switon\Tooling\Tests\ToolTestCase;
use Switon\Tooling\Tool\ReadTool;

#[CoversClass(ReadTool::class)]
class ReadToolTest extends ToolTestCase
{
    public function testSchemaMentionsLinePrefixesAndFromEndOffset(): void
    {
        $schema = $this->make(ReadTool::class)->schema('read');

        $this->assertStringContainsString('L{n}:', $schema->definition['description']);
        $this->assertStringContainsString('PNG/JPEG/GIF/WebP', $schema->definition['description']);
        $this->assertSame(['path'], $schema->definition['inputSchema']['required']);
        $this->assertArrayHasKey('offset', $schema->definition['inputSchema']['properties']);
        $this->assertArrayHasKey('limit', $schema->definition['inputSchema']['properties']);
        $this->assertStringContainsString('from end', $schema->definition['inputSchema']['properties']['offset']['description']);
    }

    public function testReadPngReturnsMediaPartWithoutPrompt(): void
    {
        $file = $this->tempBinaryFile("\x89PNG\r\n\x1a\n", 'shot.png');

        $result = $this->callTool($this->make(ReadTool::class), 'read', ['path' => $file]);

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertSame('', $result->content);
        $this->assertCount(1, $result->parts);
        $this->assertInstanceOf(MediaPart::class, $result->parts[0]);
        $this->assertSame('image/png', $result->parts[0]->mime);
        $this->assertSame(Path::absolute($file), $result->parts[0]->ref);
    }

    public function testReadSvgStaysTextNotMedia(): void
    {
        $file = $this->tempBinaryFile('<svg xmlns="http://www.w3.org/2000/svg"/>', 'icon.svg');

        $result = $this->callTool($this->make(ReadTool::class), 'read', ['path' => $file]);

        $this->assertIsArray($result);
        $this->assertStringContainsString('L1: <svg', $result['content']);
    }

    public function testReadMp4StaysTextNotMedia(): void
    {
        $file = $this->tempBinaryFile("ftyp\n", 'clip.mp4');

        $result = $this->callTool($this->make(ReadTool::class), 'read', ['path' => $file]);

        $this->assertIsArray($result);
        $this->assertStringStartsWith('L1: ', $result['content']);
    }

    public function testReadImageIgnoresOffsetAndLimit(): void
    {
        $file = $this->tempBinaryFile("\x89PNG\r\n\x1a\n", 'a.png');

        $result = $this->callTool($this->make(ReadTool::class), 'read', [
            'path' => $file,
            'offset' => 1,
            'limit' => 1,
        ]);

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertSame(Path::absolute($file), $result->parts[0]->ref);
        $this->assertSame('image/png', $result->parts[0]->mime);
    }

    public function testReadWholeFilePrefixesLines(): void
    {
        $file = $this->tempFile("a\nb\n");

        $result = $this->callTool($this->make(ReadTool::class), 'read', ['path' => $file]);

        $this->assertSame("L1: a\nL2: b", $result['content']);
        $this->assertArrayNotHasKey('next', $result);
    }

    public function testReadEmptyFileReturnsEmptyContent(): void
    {
        $file = $this->tempFile('');

        $result = $this->callTool($this->make(ReadTool::class), 'read', ['path' => $file]);

        $this->assertSame('', $result['content']);
        $this->assertArrayNotHasKey('next', $result);
    }

    public function testReadWithoutTrailingNewlineStillNumbersLines(): void
    {
        $file = $this->tempFile("a\nb");

        $result = $this->callTool($this->make(ReadTool::class), 'read', ['path' => $file]);

        $this->assertSame("L1: a\nL2: b", $result['content']);
    }

    public function testReadCrlfFileDoesNotKeepCarriageReturns(): void
    {
        $file = $this->tempFile("a\r\nb\r\n");

        $result = $this->callTool($this->make(ReadTool::class), 'read', ['path' => $file]);

        $this->assertSame("L1: a\nL2: b", $result['content']);
    }

    public function testReadClassicMacCrFileSplitsLines(): void
    {
        $file = $this->tempFile("a\rb\r");

        $result = $this->callTool($this->make(ReadTool::class), 'read', ['path' => $file]);

        $this->assertSame("L1: a\nL2: b", $result['content']);
    }

    public function testOffsetOnCrlfFileUsesSameLineCount(): void
    {
        $file = $this->tempFile("one\r\ntwo\r\nthree\r\n");

        $result = $this->callTool($this->make(ReadTool::class), 'read', [
            'path' => $file,
            'offset' => -1,
            'limit' => 1,
        ]);

        $this->assertSame('L3: three', $result['content']);
        $this->assertSame(3, $result['total_lines']);
    }

    public function testReadStopsAtByteBudgetOnLineBoundary(): void
    {
        $file = $this->tempFile("aaaa\nbbbb\ncccc\n");
        $read = $this->make(ReadTool::class, ['maxBytes' => 9]);

        $result = $this->callTool($read, 'read', ['path' => $file]);

        $this->assertSame("L1: aaaa\nL2: bbbb", $result['content']);
        $this->assertSame(['offset' => 3], $result['next']);
    }

    public function testOversizedFirstLineIsReturnedWholeSoPagingAdvances(): void
    {
        $file = $this->tempFile(str_repeat('x', 80) . "\ny\n");
        $read = $this->make(ReadTool::class, ['maxBytes' => 10]);

        $result = $this->callTool($read, 'read', ['path' => $file]);

        $this->assertSame('L1: ' . str_repeat('x', 80), $result['content']);
        $this->assertSame(['offset' => 2], $result['next']);
    }

    public function testReadRejectsMissingPath(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File not found');

        $this->callTool(
            $this->make(ReadTool::class),
            'read',
            ['path' => '/tmp/switon-file-read-missing-' . uniqid('', true)],
        );
    }

    public function testReadRejectsWhenBytesUnreadable(): void
    {
        $file = $this->tempFile('hello');
        $read = $this->make(UnreadableReadTool::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to read file');

        $this->callTool($read, 'read', ['path' => $file]);
    }

    public function testReadLinesRejectsWhenBytesUnreadable(): void
    {
        $file = $this->tempFile("a\nb\n");
        $read = $this->make(UnreadableReadTool::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to read file');

        $this->callTool($read, 'read', ['path' => $file, 'offset' => 1, 'limit' => 1]);
    }

    public function testOffsetAndLimitWindow(): void
    {
        $file = $this->tempFile("one\ntwo\nthree\nfour\n");

        $result = $this->callTool($this->make(ReadTool::class), 'read', [
            'path' => $file,
            'offset' => 2,
            'limit' => 2,
        ]);

        $this->assertSame("L2: two\nL3: three", $result['content']);
        $this->assertArrayNotHasKey('offset', $result);
        $this->assertArrayNotHasKey('limit', $result);
        $this->assertSame(4, $result['total_lines']);
        $this->assertSame(['offset' => 4], $result['next']);
        $this->assertArrayNotHasKey('limit', $result['next']);
    }

    public function testOffsetOnlyDefaultsLimitToMaxLines(): void
    {
        $file = $this->tempFile("a\nb\nc\n");
        $read = $this->make(ReadTool::class, ['maxLines' => 2]);

        $result = $this->callTool($read, 'read', ['path' => $file, 'offset' => 1]);

        $this->assertSame("L1: a\nL2: b", $result['content']);
        $this->assertArrayNotHasKey('limit', $result);
        $this->assertSame(['offset' => 3], $result['next']);
    }

    public function testLimitOnlyStartsAtLineOne(): void
    {
        $file = $this->tempFile("a\nb\nc\n");

        $result = $this->callTool($this->make(ReadTool::class), 'read', [
            'path' => $file,
            'limit' => 1,
        ]);

        $this->assertSame('L1: a', $result['content']);
        $this->assertArrayNotHasKey('offset', $result);
        $this->assertSame(['offset' => 2], $result['next']);
    }

    public function testLimitIsClampedToMaxLines(): void
    {
        $file = $this->tempFile("a\nb\nc\nd\n");
        $read = $this->make(ReadTool::class, ['maxLines' => 2]);

        $result = $this->callTool($read, 'read', [
            'path' => $file,
            'offset' => 1,
            'limit' => 99,
        ]);

        $this->assertSame("L1: a\nL2: b", $result['content']);
        $this->assertArrayNotHasKey('limit', $result);
        $this->assertSame(['offset' => 3], $result['next']);
    }

    public function testByteBudgetBeatsLineLimitWhenEarlier(): void
    {
        $file = $this->tempFile("aaaa\nbbbb\ncccc\ndddd\n");
        $read = $this->make(ReadTool::class, ['maxBytes' => 9, 'maxLines' => 10]);

        $result = $this->callTool($read, 'read', [
            'path' => $file,
            'offset' => 1,
            'limit' => 10,
        ]);

        $this->assertSame("L1: aaaa\nL2: bbbb", $result['content']);
        $this->assertSame(['offset' => 3], $result['next']);
    }

    public function testReadToEndIsNotTruncated(): void
    {
        $file = $this->tempFile("a\nb\nc\n");

        $result = $this->callTool($this->make(ReadTool::class), 'read', [
            'path' => $file,
            'offset' => 2,
            'limit' => 10,
        ]);

        $this->assertSame("L2: b\nL3: c", $result['content']);
        $this->assertArrayNotHasKey('next', $result);
    }

    public function testNegativeOffsetMinusOneIsLastLine(): void
    {
        $file = $this->tempFile("one\ntwo\nthree\nfour\n");

        $result = $this->callTool($this->make(ReadTool::class), 'read', [
            'path' => $file,
            'offset' => -1,
            'limit' => 1,
        ]);

        $this->assertSame('L4: four', $result['content']);
        $this->assertArrayNotHasKey('offset', $result);
        $this->assertArrayNotHasKey('next', $result);
    }

    public function testNegativeOffsetWithLimitReadsForward(): void
    {
        $file = $this->tempFile("one\ntwo\nthree\nfour\n");

        // -3 => start at line 2; limit 2 => lines 2-3 (not "last 3 lines").
        $result = $this->callTool($this->make(ReadTool::class), 'read', [
            'path' => $file,
            'offset' => -3,
            'limit' => 2,
        ]);

        $this->assertSame("L2: two\nL3: three", $result['content']);
        $this->assertArrayNotHasKey('offset', $result);
        $this->assertSame(['offset' => 4], $result['next']);
    }

    public function testNegativeOffsetWithoutLimitReadsThroughEof(): void
    {
        $file = $this->tempFile("one\ntwo\nthree\nfour\n");

        $result = $this->callTool($this->make(ReadTool::class), 'read', [
            'path' => $file,
            'offset' => -2,
        ]);

        $this->assertSame("L3: three\nL4: four", $result['content']);
        $this->assertArrayNotHasKey('offset', $result);
        $this->assertArrayNotHasKey('next', $result);
    }

    public function testNegativeOffsetFirstLine(): void
    {
        $file = $this->tempFile("one\ntwo\nthree\nfour\n");

        $result = $this->callTool($this->make(ReadTool::class), 'read', [
            'path' => $file,
            'offset' => -4,
            'limit' => 1,
        ]);

        $this->assertSame('L1: one', $result['content']);
        $this->assertArrayNotHasKey('offset', $result);
    }

    public function testNegativeOffsetOvershootRejected(): void
    {
        $file = $this->tempFile("one\ntwo\nthree\nfour\n");

        $this->expectException(ToolInvalidArgumentException::class);
        $this->expectExceptionMessage('offset value invalid');

        $this->callTool($this->make(ReadTool::class), 'read', [
            'path' => $file,
            'offset' => -5,
            'limit' => 1,
        ]);
    }

    public function testOffsetZeroRejected(): void
    {
        $file = $this->tempFile("one\ntwo\n");

        $this->expectException(ToolInvalidArgumentException::class);
        $this->expectExceptionMessage('offset value invalid');

        $this->callTool($this->make(ReadTool::class), 'read', [
            'path' => $file,
            'offset' => 0,
            'limit' => 1,
        ]);
    }

    public function testPositiveOffsetPastEndRejected(): void
    {
        $file = $this->tempFile("one\ntwo\n");

        $this->expectException(ToolInvalidArgumentException::class);
        $this->expectExceptionMessage('offset value invalid');

        $this->callTool($this->make(ReadTool::class), 'read', [
            'path' => $file,
            'offset' => 99,
        ]);
    }

    public function testEmptyFileRejectsLineOffset(): void
    {
        $file = $this->tempFile('');

        $this->expectException(ToolInvalidArgumentException::class);
        $this->expectExceptionMessage('offset value invalid');

        $this->callTool($this->make(ReadTool::class), 'read', [
            'path' => $file,
            'offset' => 1,
            'limit' => 1,
        ]);
    }

    public function testEmptyFileRejectsNegativeOffset(): void
    {
        $file = $this->tempFile('');

        $this->expectException(ToolInvalidArgumentException::class);
        $this->expectExceptionMessage('offset value invalid');

        $this->callTool($this->make(ReadTool::class), 'read', [
            'path' => $file,
            'offset' => -1,
            'limit' => 1,
        ]);
    }

    public function testLimitBelowOneRejected(): void
    {
        $this->expectException(ArgumentBindException::class);
        $this->expectExceptionMessage('limit below 1');

        (new ArgumentBinder())->bind($this->make(ReadTool::class)->schema('read'), [
            'path' => '/tmp/lines.txt',
            'limit' => 0,
        ]);
    }

    public function testOffsetOnClassicMacCrFile(): void
    {
        $file = $this->tempFile("one\rtwo\rthree\r");

        $result = $this->callTool($this->make(ReadTool::class), 'read', [
            'path' => $file,
            'offset' => 2,
            'limit' => 1,
        ]);

        $this->assertSame('L2: two', $result['content']);
        $this->assertSame(3, $result['total_lines']);
    }

    public function testBlankLinesPreserved(): void
    {
        $file = $this->tempFile("a\n\nb\n");

        $result = $this->callTool($this->make(ReadTool::class), 'read', ['path' => $file]);

        $this->assertSame("L1: a\nL2: \nL3: b", $result['content']);
    }

    protected function tempFile(string $contents): string
    {
        return $this->tempBinaryFile($contents, 'f.txt');
    }

    protected function tempBinaryFile(string $contents, string $name): string
    {
        $dir = sys_get_temp_dir() . '/switon-file-read-tool-' . uniqid('', true);
        mkdir($dir);
        $file = $dir . '/' . $name;
        file_put_contents($file, $contents);

        return $file;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir() . '/switon-file-read-tool-*') ?: [] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                @unlink($dir . '/' . $entry);
            }
            @rmdir($dir);
        }

        parent::tearDown();
    }
}

/** ReadTool stub that always fails at open (DI-wired for path resolve). */
final class UnreadableReadTool extends ReadTool
{
    protected function openFile(string $path)
    {
        Exception::raise('Failed to read file.');
    }
}
