<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Segment;
use Switon\Ai\Tests\TestCase;

#[CoversClass(Segment::class)]
class SegmentTest extends TestCase
{
    public function testDecodePassesToolsThroughWithoutNormalization(): void
    {
        // Tools are raw data; union and dedupe happen when a charter is assembled.
        $segment = Segment::decode([
            'prompt' => 'Hello',
            'tools' => [' echo ', '', 'echo', 'bash'],
        ]);

        $this->assertSame('Hello', $segment->prompt);
        $this->assertSame([' echo ', '', 'echo', 'bash'], $segment->tools);
    }
}
