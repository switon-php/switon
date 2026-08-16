<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use SplFileInfo;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Tooling\Exception;
use Switon\Tooling\Spiller;
use Switon\Tooling\SpillerInterface;
use Switon\Tooling\Tests\ToolTestCase;

use function file_get_contents;
use function file_put_contents;
use function is_file;
use function mkdir;
use function strlen;
use function sys_get_temp_dir;

#[CoversClass(Spiller::class)]
class SpillerTest extends ToolTestCase
{
    public function testCeilingExceededNamesTheLimit(): void
    {
        $this->assertSame(
            "\n[output exceeded 32 MiB ceiling; remaining bytes discarded]",
            Spiller::ceilingExceeded(32 * 1024 * 1024),
        );
        $this->assertSame(
            "\n[output exceeded 100 bytes ceiling; remaining bytes discarded]",
            Spiller::ceilingExceeded(100),
        );
    }

    public function testSpillPreviewUsesStableRecallLine(): void
    {
        $dir = $this->tempDatadir();
        $out = $this->spiller()->spill($dir, 'tool-result/a.txt', str_repeat('A', 2000), budgetKiB: 1, keep: 4);

        $this->assertSame(
            "AAAA\n...[truncated: 1992 bytes; recall: @thread/tool-result/a.txt]...\nAAAA",
            $out,
        );
    }

    public function testSpillStringUnderBudgetReturnsInline(): void
    {
        $dir = $this->tempDatadir();
        $out = $this->spiller()->spill($dir, 'tool-result/small.txt', 'hello', budgetKiB: 32, keep: 1024);

        $this->assertSame('hello', $out);
        $this->assertFalse(is_file($dir . '/tool-result/small.txt'));
    }

    public function testSpillStringOverBudgetWritesAndPreviews(): void
    {
        $dir = $this->tempDatadir();
        $body = str_repeat('A', 2000) . 'MIDDLE' . str_repeat('Z', 2000);
        $out = $this->spiller()->spill($dir, 'tool-result/big.txt', $body, budgetKiB: 1, keep: 1024);

        $this->assertStringContainsString('recall: @thread/tool-result/big.txt', $out);
        $this->assertStringContainsString('truncated:', $out);
        $this->assertStringNotContainsString('MIDDLE', $out);
        $this->assertSame($body, file_get_contents($dir . '/tool-result/big.txt'));
    }

    public function testSpillFileUnderBudgetReturnsContentsAndRemovesSource(): void
    {
        $dir = $this->tempDatadir();
        $tmp = $dir . '/tmp-small.txt';
        file_put_contents($tmp, 'from-file');
        $out = $this->spiller()->spill($dir, 'tool-result/kept.txt', new SplFileInfo($tmp), budgetKiB: 32, keep: 1024);

        $this->assertSame('from-file', $out);
        $this->assertFalse(is_file($tmp));
        $this->assertFalse(is_file($dir . '/tool-result/kept.txt'));
    }

    public function testSpillFileOverBudgetRenamesAndPreviews(): void
    {
        $dir = $this->tempDatadir();
        $tmp = sys_get_temp_dir() . '/switon-spill-src-' . bin2hex(random_bytes(4)) . '.txt';
        $body = str_repeat('A', 2000) . 'MIDDLE' . str_repeat('Z', 2000);
        file_put_contents($tmp, $body);
        $out = $this->spiller()->spill($dir, 'tool-result/moved.txt', new SplFileInfo($tmp), budgetKiB: 1, keep: 1024);

        $this->assertStringContainsString('recall: @thread/tool-result/moved.txt', $out);
        $this->assertStringNotContainsString('MIDDLE', $out);
        $this->assertFalse(is_file($tmp));
        $this->assertSame($body, file_get_contents($dir . '/tool-result/moved.txt'));
    }

    public function testCompactBudgetSpillsBeforeRawBudget(): void
    {
        $dir = $this->tempDatadir();
        $body = str_repeat('Q', 5000);
        $spiller = $this->spiller();
        $raw = $spiller->spill($dir, 'tool-result/raw.txt', $body, budgetKiB: 32, keep: 1024);
        $compact = $spiller->spill($dir, 'tool-result/compact.txt', $body, budgetKiB: 4, keep: 128);

        $this->assertSame($body, $raw);
        $this->assertStringStartsWith(str_repeat('Q', 128) . "\n...[truncated:", $compact);
        $this->assertStringEndsWith("]...\n" . str_repeat('Q', 128), $compact);
    }

    public function testSpillDoesNotOverwriteExistingTargetFile(): void
    {
        $dir = $this->tempDatadir();
        $body = str_repeat('A', 3000) . 'MIDDLE' . str_repeat('Z', 3000);
        $spiller = $this->spiller();
        $first = $spiller->spill($dir, 'tool-result/kept.txt', $body, budgetKiB: 1, keep: 1024);
        $second = $spiller->spill($dir, 'tool-result/kept.txt', $first, budgetKiB: 4, keep: 128);

        $this->assertSame($body, file_get_contents($dir . '/tool-result/kept.txt'));
        $this->assertStringContainsString('recall: @thread/tool-result/kept.txt', $second);
        $this->assertStringStartsWith(str_repeat('A', 128) . "\n...[truncated:", $second);
        $this->assertStringEndsWith("]...\n" . str_repeat('Z', 128), $second);
        $this->assertLessThan(strlen($first), strlen($second));
    }

    public function testSpillDoesNotOverwriteExistingTargetFileWhenSourceIsFile(): void
    {
        $dir = $this->tempDatadir();
        $original = str_repeat('A', 3000) . 'MIDDLE' . str_repeat('Z', 3000);
        $spiller = $this->spiller();
        $spiller->spill($dir, 'tool-result/kept.txt', $original, budgetKiB: 1, keep: 1024);

        $incoming = $dir . '/incoming.txt';
        file_put_contents($incoming, str_repeat('B', 5000));

        $out = $spiller->spill($dir, 'tool-result/kept.txt', new SplFileInfo($incoming), budgetKiB: 1, keep: 128);

        // The stored body stays the first spill; the incoming file is consumed, not merged in.
        $this->assertSame($original, file_get_contents($dir . '/tool-result/kept.txt'));
        $this->assertFalse(is_file($incoming));
        $this->assertStringStartsWith(str_repeat('A', 128) . "\n...[truncated:", $out);
        $this->assertStringEndsWith("]...\n" . str_repeat('Z', 128), $out);
    }

    public function testSpillPreviewDoesNotSplitUtf8Characters(): void
    {
        $dir = $this->tempDatadir();
        $body = str_repeat('中', 400) . str_repeat('文', 400);
        $out = $this->spiller()->spill($dir, 'tool-result/utf8.txt', $body, budgetKiB: 1, keep: 5);

        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
        $this->assertStringContainsString('recall: @thread/tool-result/utf8.txt', $out);
        $this->assertLessThan(strlen($body), strlen($out));
    }

    public function testSpillRejectsEmptyDatadir(): void
    {
        $this->expectException(Exception::class);
        $this->spiller()->spill('', 'tool-result/a.txt', 'x', budgetKiB: 32, keep: 1024);
    }

    public function testSpillRejectsAbsoluteTargetPath(): void
    {
        $this->expectException(Exception::class);
        $this->spiller()->spill($this->tempDatadir(), '/abs/out.txt', 'x', budgetKiB: 32, keep: 1024);
    }

    public function testSpillMissingSourceFileRaises(): void
    {
        $dir = $this->tempDatadir();

        $this->expectException(Exception::class);
        $this->spiller()->spill($dir, 'tool-result/missing.txt', new SplFileInfo($dir . '/nope.txt'), budgetKiB: 32, keep: 1024);
    }

    public function testSpillPreviewOmitsAtLeastOneByteNearTheTriggerLine(): void
    {
        $dir = $this->tempDatadir();
        $spiller = $this->spiller();

        // Just over the spill trigger: edges are min(keep, half) each — the preview may exceed
        // the body by up to one marker, but it must always omit at least one byte.
        foreach ([1025, 1071, 1100, 2048, 2049] as $size) {
            $body = str_repeat('x', $size);
            $out = $spiller->spill($dir, 'tool-result/near-' . $size . '.txt', $body, budgetKiB: 1, keep: 1024);

            $this->assertMatchesRegularExpression('/truncated: [1-9]\d* bytes/', $out);
        }
    }

    public function testSpillFilePreviewDoesNotSplitUtf8Characters(): void
    {
        $dir = $this->tempDatadir();
        $tmp = $dir . '/tmp-utf8.txt';
        file_put_contents($tmp, '甲' . str_repeat('乙', 700) . '丙');

        $out = $this->spiller()->spill($dir, 'tool-result/utf8-file.txt', new SplFileInfo($tmp), budgetKiB: 1, keep: 5);

        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
        $this->assertStringContainsString('recall: @thread/tool-result/utf8-file.txt', $out);
        $this->assertStringStartsWith('甲', $out);
        $this->assertStringEndsWith("]...\n乙丙", $out);
    }

    public function testSpillPreviewOmitsBytesInsteadOfReportingZero(): void
    {
        $dir = $this->tempDatadir();
        $body = str_repeat('A', 1100);

        $out = $this->spiller()->spill($dir, 'tool-result/zero.txt', $body, budgetKiB: 1, keep: 1024);

        $this->assertStringNotContainsString('truncated: 0 bytes', $out);
    }

    public function testSpillResolvesAliasedDatadirForPreview(): void
    {
        $dir = $this->tempDatadir();
        $this->container->get(PathAliasInterface::class)->set('@spill-alias', $dir);

        $body = str_repeat('A', 5000);
        $out = $this->spiller()->spill('@spill-alias', 'tool-result/alias.txt', $body, budgetKiB: 1, keep: 100);

        $this->assertStringStartsWith('AAAA', $out);
        $this->assertStringEndsWith('AAAA', $out);
        $this->assertSame($body, file_get_contents($dir . '/tool-result/alias.txt'));
    }

    public function testSpillResolvesAliasedDatadirWhenSourceIsFile(): void
    {
        $dir = $this->tempDatadir();
        $this->container->get(PathAliasInterface::class)->set('@spill-alias', $dir);

        $src = $dir . '/source.txt';
        file_put_contents($src, str_repeat('B', 5000));

        $out = $this->spiller()->spill('@spill-alias', 'tool-result/alias-file.txt', new SplFileInfo($src), budgetKiB: 1, keep: 100);

        $this->assertStringContainsString('recall: @thread/tool-result/alias-file.txt', $out);
    }

    public function testSpillResolvesAliasedDatadirOnRepeatedSpill(): void
    {
        $dir = $this->tempDatadir();
        $this->container->get(PathAliasInterface::class)->set('@spill-alias', $dir);
        $spiller = $this->spiller();

        $body = str_repeat('C', 5000);
        $spiller->spill('@spill-alias', 'tool-result/again.txt', $body, budgetKiB: 1, keep: 100);

        // Second call hits the "target already holds the body" branch.
        $out = $spiller->spill('@spill-alias', 'tool-result/again.txt', $body, budgetKiB: 1, keep: 100);

        $this->assertStringContainsString('recall: @thread/tool-result/again.txt', $out);
        $this->assertSame($body, file_get_contents($dir . '/tool-result/again.txt'));
    }

    public function testSpillRaisesWhenPreviewCannotOpenTarget(): void
    {
        // Spill moved the body into place but the preview path is unreadable — fail loudly.
        $filesystem = $this->createStub(FilesystemInterface::class);
        $filesystem->method('exists')->willReturn(true);
        $filesystem->method('size')->willReturn(5000);
        $filesystem->method('resolve')->willReturn('/nonexistent-dir/spill-preview.txt');

        $spiller = $this->make(Spiller::class, ['filesystem' => $filesystem]);
        $source = new SplFileInfo(sys_get_temp_dir() . '/spill-source-' . bin2hex(random_bytes(4)) . '.txt');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('cannot open');

        $spiller->spill(sys_get_temp_dir(), 'tool-result/gone.txt', $source, budgetKiB: 1, keep: 100);
    }

    protected function spiller(): SpillerInterface
    {
        return $this->make(Spiller::class);
    }

    protected function tempDatadir(): string
    {
        $dir = sys_get_temp_dir() . '/switon-spill-' . bin2hex(random_bytes(4));
        mkdir($dir . '/tool-result', 0777, true);

        return $dir;
    }
}
