<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Switon\Beacon\Beacon;

#[AllowMockObjectsWithoutExpectations]
class BeaconTest extends TestCase
{
    public function testBeaconClassExists(): void
    {
        $this->assertTrue(class_exists(Beacon::class));
        $this->assertSame(Beacon::class, Beacon::class);
    }
}
