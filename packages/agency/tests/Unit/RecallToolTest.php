<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Agent;
use Switon\Agency\Tests\TestCase;

#[Group('coroutine')]
class RecallToolTest extends TestCase
{
    public function testHostCharterIncludesRecall(): void
    {
        $host = $this->make(Agent::class, []);

        $this->assertArrayHasKey('recall', $host->charter()->tools);
    }
}
