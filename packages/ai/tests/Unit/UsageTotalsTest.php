<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\TokenUsage;
use Switon\Ai\UsageTotals;
use Switon\Core\Json;

#[CoversClass(UsageTotals::class)]
class UsageTotalsTest extends TestCase
{
    public function testAddFoldsCountersAndLeavesSealTime(): void
    {
        $bag = new UsageTotals(updatedAt: 10.0);
        $bag->add(new UsageTotals(new TokenUsage(1, 2), llmCalls: 1, toolCalls: 2, llmWall: 1.0));
        $bag->add(new UsageTotals(new TokenUsage(3, 4), llmCalls: 1, llmWall: 0.5, updatedAt: 99.0));

        $this->assertSame(4, $bag->usage?->input);
        $this->assertSame(6, $bag->usage?->output);
        $this->assertNull($bag->usage?->hit);
        $this->assertSame(2, $bag->llmCalls);
        $this->assertSame(2, $bag->toolCalls);
        $this->assertSame(1.5, $bag->llmWall);
        $this->assertSame(10.0, $bag->updatedAt);
    }

    public function testDecodeDropsUnknownKeysAndKeepsNullSealTime(): void
    {
        $totals = UsageTotals::decode([
            'profileId' => 'explore',
            'usage' => ['input' => 8, 'output' => 1],
            'llmCalls' => 2,
            'llmWall' => 1.23456,
        ]);

        $this->assertSame(2, $totals->llmCalls);
        $this->assertSame(8, $totals->usage?->input);
        $this->assertSame(1.235, $totals->llmWall);
        $this->assertNull($totals->updatedAt);
        $this->assertNull($totals->jsonSerialize()['updatedAt']);
        $this->assertArrayNotHasKey('profileId', Json::parse(Json::stringify($totals)));
    }
}
