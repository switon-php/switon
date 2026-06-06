<?php

declare(strict_types=1);

namespace Switon\Console\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Switon\Console\TerminalWidthDetector;

#[AllowMockObjectsWithoutExpectations]
class TerminalWidthDetectorBehaviorTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('COLUMNS');
        parent::tearDown();
    }

    public function testGetWidthUsesColumnsEnvWhenValid(): void
    {
        putenv('COLUMNS=120');
        $d = new TestableTerminalWidthDetector();
        $this->assertSame(120, $d->getWidth(80));
    }

    public function testClearCacheForcesWidthRedetection(): void
    {
        putenv('COLUMNS=120');
        $d = new TestableTerminalWidthDetector();
        $this->assertSame(120, $d->getWidth(80));

        putenv('COLUMNS=140');
        $this->assertSame(120, $d->getWidth(80));

        $d->clearCache();
        $this->assertSame(140, $d->getWidth(80));
    }

    public function testGetWidthFallsBackToDefaultWhenColumnsTooSmallAndShellCommandsFail(): void
    {
        putenv('COLUMNS=10');
        $d = new TestableTerminalWidthDetector();
        $d->shell = [
            'stty size 2>/dev/null' => null,
            'tput cols 2>/dev/null' => null,
        ];
        $this->assertSame(80, $d->getWidth(80));
    }

    public function testGetWidthHonorsDifferentDefaultsBeforeAndAfterCache(): void
    {
        putenv('COLUMNS=10');
        $d = new TestableTerminalWidthDetector();
        $d->shell = [
            'stty size 2>/dev/null' => null,
            'tput cols 2>/dev/null' => null,
        ];

        $this->assertSame(20, $d->getWidth(10));
        $this->assertSame(50, $d->getWidth(50));
    }

    public function testGetWidthUsesSttyWhenAvailable(): void
    {
        putenv('COLUMNS');
        $d = new TestableTerminalWidthDetector();
        $d->shell = [
            'stty size 2>/dev/null' => "40 101\n",
        ];
        $this->assertSame(101, $d->getWidth(80));
    }

    public function testGetWidthIgnoresNonNumericColumnsAndFallsBackToStty(): void
    {
        putenv('COLUMNS=not-a-number');
        $d = new TestableTerminalWidthDetector();
        $d->shell = [
            'stty size 2>/dev/null' => "24 99\n",
        ];

        $this->assertSame(99, $d->getWidth(80));
    }

    public function testGetWidthUsesTputWhenSttyMissing(): void
    {
        putenv('COLUMNS');
        $d = new TestableTerminalWidthDetector();
        $d->shell = [
            'stty size 2>/dev/null' => '',
            'tput cols 2>/dev/null' => "90\n",
        ];
        $this->assertSame(90, $d->getWidth(80));
    }

    public function testDetectUnixWidthReturnsZeroWhenOutputsInvalidOrTooSmall(): void
    {
        $d = new TestableTerminalWidthDetector();
        $d->shell = [
            'stty size 2>/dev/null' => "bad output\n",
            'tput cols 2>/dev/null' => "10\n",
        ];

        $this->assertSame(0, $d->exposedDetectUnixWidth());
    }

    public function testDetectWindowsWidthSupportsEnglishAndChineseAndPowershellFallback(): void
    {
        $d = new TestableTerminalWidthDetector();

        $d->shell = [
            'mode con 2>nul' => "Columns: 132\n",
        ];
        $this->assertSame(132, $d->exposedDetectWindowsWidth());

        $d->shell = [
            'mode con 2>nul' => "列: 118\n",
        ];
        $this->assertSame(118, $d->exposedDetectWindowsWidth());

        $d->shell = [
            'mode con 2>nul' => "Columns: 10\n",
            'powershell -Command "[Console]::WindowWidth" 2>nul' => "140\n",
        ];
        $this->assertSame(140, $d->exposedDetectWindowsWidth());
    }
}

class TestableTerminalWidthDetector extends TerminalWidthDetector
{
    /** @var array<string, string|null> */
    public array $shell = [];

    protected function runShellCommand(string $command): ?string
    {
        return $this->shell[$command] ?? null;
    }

    public function exposedDetectUnixWidth(): int
    {
        return $this->detectUnixWidth();
    }

    public function exposedDetectWindowsWidth(): int
    {
        return $this->detectWindowsWidth();
    }
}
