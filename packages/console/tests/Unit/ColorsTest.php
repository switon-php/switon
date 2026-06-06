<?php

declare(strict_types=1);

namespace Switon\Console\Tests\Unit;

use Switon\Console\Colors;
use Switon\Console\Tests\TestCase;

final class ColorsTest extends TestCase
{
    public function testForegroundAndBackgroundBitmasksAreDistinct(): void
    {
        $this->assertSame(0x02, Colors::FC_RED);
        $this->assertSame(0x0200, Colors::BC_RED);
        $this->assertSame(0x010000, Colors::AT_BOLD);
    }

    public function testBrightForegroundUsesHighBitRange(): void
    {
        $this->assertGreaterThan(Colors::FC_WHITE, Colors::FC_LIGHT_RED);
    }
}
