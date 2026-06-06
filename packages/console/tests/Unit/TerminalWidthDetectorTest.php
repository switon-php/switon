<?php

declare(strict_types=1);

namespace Switon\Console\Tests\Unit;

use Switon\Console\TerminalWidthDetector;
use Switon\Console\Tests\TestCase;

final class TerminalWidthDetectorTest extends TestCase
{
    protected TerminalWidthDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new TerminalWidthDetector();
    }

    public function testGetWidthReturnsInteger(): void
    {
        $this->assertIsInt($this->detector->getWidth());
    }

    public function testGetWidthWithDefaultValue(): void
    {
        $this->assertIsInt($this->detector->getWidth(100));
    }

    public function testGetWidthCachesResult(): void
    {
        $this->assertSame($this->detector->getWidth(), $this->detector->getWidth());
    }

    public function testClearCacheForcesRedetection(): void
    {
        $this->detector->clearCache();
        $this->assertIsInt($this->detector->getWidth());
    }
}
