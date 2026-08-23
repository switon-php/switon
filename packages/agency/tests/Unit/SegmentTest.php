<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Switon\Ai\Segment;

#[CoversClass(Segment::class)]
class SegmentTest extends TestCase
{
    public function testDecodePassesToolsThrough(): void
    {
        // Tools are raw data; union and dedupe happen when a charter is assembled.
        $segment = Segment::decode([
            'tools' => ['  bash  ', '', 'read', 'bash'],
        ]);

        $this->assertSame(['  bash  ', '', 'read', 'bash'], $segment->tools);
    }
}
