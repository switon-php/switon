<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Charter;
use Switon\Ai\Model;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\ToolSchema;

#[CoversClass(Charter::class)]
class CharterTest extends TestCase
{
    public function testDefaultsAreEmpty(): void
    {
        $charter = Charter::of();

        $this->assertSame('', $charter->prompt);
        $this->assertSame([], $charter->tools);
        $this->assertNull($charter->toolChoice);
        $this->assertSame('', $charter->model);
        $this->assertNull($charter->effort);
    }

    public function testHoldsPromptToolsAndToolChoice(): void
    {
        $charter = Charter::of(prompt: 'Be concise.', tools: ['get_weather' => new ToolSchema(description: 'Weather lookup.')], toolChoice: 'required');

        $this->assertSame('Be concise.', $charter->prompt);
        $this->assertSame('required', $charter->toolChoice);
        $this->assertCount(1, $charter->tools);
        $this->assertFalse($charter->invalidated());
    }

    public function testInvalidateMarksCharterStale(): void
    {
        $charter = Charter::of();

        $this->assertFalse($charter->invalidated());
        $charter->invalidate();
        $this->assertTrue($charter->invalidated());
    }

    public function testEffortDefaultsToNull(): void
    {
        $this->assertNull(Charter::of()->effort);
        $this->assertNull((new Charter())->effort);
    }

    public function testHoldsEffortLevel(): void
    {
        $charter = Charter::of(effort: 'high');

        $this->assertSame('high', $charter->effort);
    }
}
