<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Tests\TestCase;
use Switon\Core\Json;
use Switon\Ai\TokenUsage;

#[CoversClass(TokenUsage::class)]
class TokenUsageTest extends TestCase
{
    public function testAddSumsCounts(): void
    {
        $usage = new TokenUsage();
        $usage->add(new TokenUsage(input: 10, output: 5));
        $usage->add(new TokenUsage(input: 3, output: 2));

        $this->assertSame(13, $usage->input);
        $this->assertSame(7, $usage->output);
        $this->assertSame(20, $usage->total());
    }

    public function testAddSumsCacheAndReasoningBreakdown(): void
    {
        $usage = new TokenUsage();
        $usage->add(new TokenUsage(
            input: 100,
            output: 5,
            cacheRead: 80,
            reasoning: 3,
        ));
        $usage->add(new TokenUsage(
            input: 50,
            output: 2,
            cacheWrite: 40,
            reasoning: 7,
        ));

        $this->assertSame(150, $usage->input);
        $this->assertSame(80, $usage->cacheRead);
        $this->assertSame(40, $usage->cacheWrite);
        $this->assertSame(10, $usage->reasoning);
        $this->assertSame(30, $usage->uncached());
    }

    public function testAddClearsHitSnapshots(): void
    {
        $usage = new TokenUsage(input: 100, cacheRead: 50, hit: 0.5, ideal: 0.9);
        $usage->add(new TokenUsage(input: 10));

        $this->assertNull($usage->hit);
        $this->assertNull($usage->ideal);
    }

    public function testTotalAndUncached(): void
    {
        $this->assertSame(0, (new TokenUsage())->total());
        $this->assertSame(10, (new TokenUsage(input: 10))->total());
        $this->assertSame(15, (new TokenUsage(input: 10, output: 5))->total());
        $this->assertSame(100, (new TokenUsage(input: 100))->uncached());
        $this->assertSame(20, (new TokenUsage(input: 100, cacheRead: 80))->uncached());
        $this->assertSame(20, (new TokenUsage(input: 100, cacheRead: 50, cacheWrite: 30))->uncached());
    }

    public function testStampHitRoundsToThreeDecimals(): void
    {
        $usage = new TokenUsage(input: 943, output: 5, cacheRead: 768);
        $usage->stampHit(new TokenUsage(input: 927, output: 27));

        $this->assertSame(0.814, $usage->hit);
        $this->assertSame(0.983, $usage->ideal);
    }

    public function testStampHitIdealUsesOnlyPreviousInput(): void
    {
        $usage = new TokenUsage(input: 2131, cacheRead: 1664);
        $usage->stampHit(new TokenUsage(input: 1698, output: 1010, reasoning: 500));

        $this->assertSame(0.781, $usage->hit);
        $this->assertSame(0.797, $usage->ideal);
    }

    public function testStampHitNullWithoutPreviousOrInput(): void
    {
        $first = new TokenUsage(input: 100, cacheRead: 0);
        $first->stampHit(null);
        $this->assertSame(0.0, $first->hit);
        $this->assertNull($first->ideal);

        $empty = new TokenUsage();
        $empty->stampHit(new TokenUsage(input: 10, output: 1));
        $this->assertNull($empty->hit);
        $this->assertNull($empty->ideal);
    }

    public function testStampHitClampsHitAndNullsIdealWhenInputShrinks(): void
    {
        $over = new TokenUsage(input: 100, cacheRead: 150);
        $over->stampHit(new TokenUsage(input: 80));
        $this->assertSame(1.0, $over->hit);
        $this->assertSame(0.8, $over->ideal);

        $afterCompact = new TokenUsage(input: 500, cacheRead: 100);
        $afterCompact->stampHit(new TokenUsage(input: 5000));
        $this->assertSame(0.2, $afterCompact->hit);
        $this->assertNull($afterCompact->ideal);
    }

    public function testJsonEncodeDecodeRoundTrip(): void
    {
        $usage = new TokenUsage(
            input: 3_545,
            output: 124,
            cacheRead: 3_085,
            cacheWrite: 0,
            reasoning: 0,
            hit: 0.87,
            ideal: 0.95,
        );
        $encoded = Json::parse(Json::stringify($usage));
        $this->assertSame([
            'input' => 3_545,
            'output' => 124,
            'cacheRead' => 3_085,
            'cacheWrite' => 0,
            'reasoning' => 0,
            'hit' => 0.87,
            'ideal' => 0.95,
        ], $encoded);

        $decoded = TokenUsage::decode($encoded);
        $this->assertSame(3_545, $decoded->input);
        $this->assertSame(124, $decoded->output);
        $this->assertSame(3_669, $decoded->total());
        $this->assertSame(460, $decoded->uncached());
        $this->assertSame(0.87, $decoded->hit);
        $this->assertSame(0.95, $decoded->ideal);
    }

    public function testDecodeRoundTripsUnstampedHitAndIdeal(): void
    {
        $decoded = TokenUsage::decode([
            'input' => 500,
            'output' => 25,
            'cacheRead' => 0,
            'cacheWrite' => 0,
            'reasoning' => 0,
            'hit' => null,
            'ideal' => null,
        ]);

        $this->assertNull($decoded->hit);
        $this->assertNull($decoded->ideal);
        $this->assertSame(525, $decoded->total());
    }
}
