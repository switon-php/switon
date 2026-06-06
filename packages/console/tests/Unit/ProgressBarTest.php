<?php

declare(strict_types=1);

namespace Switon\Console\Tests\Unit;

use Switon\Console\ProgressBar;
use Switon\Console\Tests\TestCase;
use Switon\Core\ConsoleInterface;
use Stringable;

final class ProgressBarTest extends TestCase
{
    public function testPublicProgressFlowStartsAutomatically(): void
    {
        $progress = $this->makeProgressBar();
        $progress->advance();

        $this->assertSame(1, $progress->getMax());
        $this->assertSame(1, $progress->getCurrent());
        $this->assertSame(100.0, $progress->getPercent());

        $progress->finish();

        $this->assertSame(1, $progress->getCurrent());
        $this->assertSame(1, $progress->getConsole()->writeLnCount);
    }

    public function testSetProgressStartsWithCustomMaxAndCappedValue(): void
    {
        $progress = $this->makeProgressBar();
        $progress->setProgress(7);

        $this->assertSame(1, $progress->getMax());
        $this->assertSame(1, $progress->getCurrent());
        $this->assertSame(100.0, $progress->getPercent());
    }

    public function testStartWithZeroMaxUsesOne(): void
    {
        $progress = $this->makeProgressBar();
        $progress->start(0);

        $this->assertSame(1, $progress->getMax());
        $this->assertSame(0, $progress->getCurrent());
        $this->assertSame(0.0, $progress->getPercent());
    }

    public function testSetFormatAndMessageAffectOutput(): void
    {
        $progress = $this->makeProgressBar();
        $progress->setFormat('%current%/%max% %percent%% %elapsed%/%remaining% %rate%/s');
        $progress->setMessage('queued');
        $progress->setState(current: 2, max: 5, startTime: microtime(true) - 10.0);

        $output = $progress->exposeBuildOutput();

        $this->assertSame($progress, $progress->setFormat('%current%/%max% %percent%%'));
        $this->assertSame($progress, $progress->setMessage('queued'));
        $this->assertStringContainsString('2/5', $output);
        $this->assertStringContainsString('queued', $output);
        $this->assertStringNotContainsString('%current%', $output);
    }

    public function testBuildOutputUsesPlaceholderFallbacks(): void
    {
        $progress = $this->makeProgressBar();
        $progress->setFormat('%elapsed% %remaining% %rate%/s');
        $progress->setState(current: 0, max: 10, startTime: microtime(true));

        $output = $progress->exposeBuildOutput();

        $this->assertStringContainsString('--:--', $output);
        $this->assertStringContainsString('0.0/s', $output);
    }

    public function testBuildBarHandlesEmptyPartialAndFullProgress(): void
    {
        $progress = $this->makeProgressBar();
        $progress->setBarWidthForTest(4);

        $this->assertSame('[    ]', $progress->exposeBuildBar(0.0));
        $this->assertSame('[=>  ]', $progress->exposeBuildBar(50.0));
        $this->assertSame('[====]', $progress->exposeBuildBar(100.0));
    }

    public function testElapsedRateAndRemainingHelpersHandleEdgeCases(): void
    {
        $progress = $this->makeProgressBar();

        $this->assertSame(0.0, $progress->exposeGetElapsedTime());

        $progress->setState(current: 0, max: 10, startTime: microtime(true) - 1.0);
        $this->assertSame(0.0, $progress->exposeGetEstimatedRemaining());

        $progress->setState(current: 2, max: 10, startTime: microtime(true) + 10.0);
        $this->assertSame(0.0, $progress->exposeGetEstimatedRemaining());
        $this->assertSame(0.0, $progress->exposeGetRate());
    }

    public function testFormatTimeUsesHoursWhenNeeded(): void
    {
        $progress = $this->makeProgressBar();

        $this->assertSame('01:01:01', $progress->exposeFormatTime(3661.9));
    }

    private function makeProgressBar(): TestableProgressBar
    {
        $progress = new TestableProgressBar();
        $progress->attachConsole(new RecordingConsole());

        return $progress;
    }
}

final class TestableProgressBar extends ProgressBar
{
    public function attachConsole(RecordingConsole $console): void
    {
        $this->console = $console;
    }

    public function getConsole(): RecordingConsole
    {
        return $this->console;
    }

    public function setState(int $current, int $max, ?float $startTime): void
    {
        $this->current = $current;
        $this->max = $max;
        $this->startTime = $startTime;
    }

    public function setBarWidthForTest(int $barWidth): void
    {
        $this->barWidth = $barWidth;
    }

    public function exposeBuildOutput(): string
    {
        return $this->buildOutput();
    }

    public function exposeBuildBar(float $percent): string
    {
        return $this->buildBar($percent);
    }

    public function exposeGetElapsedTime(): float
    {
        return $this->getElapsedTime();
    }

    public function exposeGetEstimatedRemaining(): float
    {
        return $this->getEstimatedRemaining();
    }

    public function exposeGetRate(): float
    {
        return $this->getRate();
    }

    public function exposeFormatTime(float $seconds): string
    {
        return $this->formatTime($seconds);
    }
}

final class RecordingConsole implements ConsoleInterface
{
    public int $writeCount = 0;
    public int $writeLnCount = 0;

    public function isSupportColor(): bool
    {
        return false;
    }

    public function colorize(string $text, int $options = 0, int $width = 0): string
    {
        return $text;
    }

    public function sampleColorizer(): void
    {
    }

    public function write(string|Stringable $message, array $context = [], int $options = 0): void
    {
        $this->writeCount++;
    }

    public function writeLn(string|Stringable $message = '', array $context = [], int $options = 0): void
    {
        $this->writeLnCount++;
    }

    public function debug(string|Stringable $message = '', array $context = [], int $options = 0): void
    {
    }

    public function info(string|Stringable $message, array $context = []): void
    {
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
    }

    public function success(string|Stringable $message, array $context = []): void
    {
    }

    public function error(string|Stringable $message, array $context = [], int $code = 1): int
    {
        return $code;
    }

    public function progress(string|Stringable $message, mixed $value = null): void
    {
    }

    public function read(): string
    {
        return '';
    }

    public function ask(string $message): string
    {
        return '';
    }

    public function confirm(string $message, bool $default = true): bool
    {
        return $default;
    }

    public function choice(string $message, array $options, string|int|null $default = null): string|int
    {
        return $default ?? '';
    }

    public function secret(string $message): string
    {
        return '';
    }

    public function block(string|array $messages, ?string $type = null, ?string $prefix = null, bool $padding = true): void
    {
    }

    public function section(string $message): void
    {
    }

    public function note(string $message): void
    {
    }

    public function caution(string $message): void
    {
    }

    public function listing(array $items): void
    {
    }

    public function table(array $headers, array $rows, int $minWidth = 8, bool $withRowNumber = true): void
    {
    }

    public function newLine(int $count = 1): void
    {
    }

    public function line(string $message = ''): void
    {
    }
}
