<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Cdp\ElementLocation;

class ElementLocationTest extends TestCase
{
    #[Test]
    public function aCrossOriginFrameCarriesItsOwnSession(): void
    {
        $top = new ElementLocation(1.0, 2.0, '', ['x' => 0.0, 'y' => 0.0], '#5');
        $frame = new ElementLocation(3.0, 4.0, 'child', ['x' => 8.0, 'y' => 9.0], '#9:child/4');

        $this->assertFalse($top->isCrossOriginFrame());
        $this->assertTrue($frame->isCrossOriginFrame());
        $this->assertSame('child', $frame->sessionId);
        $this->assertSame(8.0, $frame->offset['x']);
    }
}
