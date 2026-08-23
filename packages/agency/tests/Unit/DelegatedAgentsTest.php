<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Agent;
use Switon\Agency\Tests\TestCase;

#[CoversClass(Agent::class)]
#[Group('coroutine')]
class DelegatedAgentsTest extends TestCase
{
    public function testAddGetHasInstance(): void
    {
        $main = $this->make(Agent::class, []);
        $reviewer = $this->make(Agent::class, [
            'profileId' => 'reviewer',
            'parent' => $main,
        ]);
        $leaf = $this->leafOf($reviewer);

        $this->registerChildForTest($main, $reviewer);

        $this->assertSame([$leaf => $reviewer], $this->liveChildren($main));
        $this->assertArrayHasKey($leaf, $this->liveChildren($main));
        $this->assertSame($main->threadId() . '.' . $leaf, $this->requireSubagent($main, $leaf)->threadId());
    }

    public function testLeafRegistryKey(): void
    {
        $main = $this->testAgent();
        $sub = $this->make(Agent::class, [
            'profileId' => 'lite',
            'parent' => $main,
        ]);
        $leaf = $this->leafOf($sub);

        $this->registerChildForTest($main, $sub);

        $this->assertArrayHasKey($leaf, $this->liveChildren($main));
        $this->assertMatchesRegularExpression('/^t[1-9]\d{4,6}$/', $leaf);
    }

    public function testRemoveClearsLeafRegistry(): void
    {
        $main = $this->testAgent();
        $sub = $this->make(Agent::class, [
            'profileId' => 'lite',
            'parent' => $main,
        ]);
        $leaf = $this->leafOf($sub);

        $this->registerChildForTest($main, $sub);
        $this->shutdownChildForTest($main, $leaf);

        $this->assertArrayNotHasKey($leaf, $this->liveChildren($main));
    }

    public function testDirectChildKillUnregistersFromParentRoster(): void
    {
        $main = $this->testAgent();
        $sub = $this->spawnDelegated($main, 'lite');
        $leaf = $this->leafOf($sub);

        $this->shutdownChildForTest($main, $leaf);

        $this->assertArrayNotHasKey($leaf, $this->liveChildren($main));
    }

}
