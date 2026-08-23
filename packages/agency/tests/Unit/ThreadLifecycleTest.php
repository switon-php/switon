<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Thread\Threads;
use Switon\Agency\Tests\TestCase;
use Switon\Core\FilesystemInterface;
use Switon\Agency\Agent;
use Switon\Core\PathAliasInterface;

#[CoversClass(Threads::class)]
#[Group('coroutine')]
class ThreadLifecycleTest extends TestCase
{
    public function testCreateEnsuresLeafWithoutMeta(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $agent = $this->make(Agent::class, []);
        $fs = $this->container->get(FilesystemInterface::class);
        $dir = $this->threadLeafDir($agent->threadId());

        $this->assertTrue($fs->exists($dir . '/state.json'));
        $this->assertFalse($fs->exists($dir . '/conversation.jsonl'));
        $this->assertFalse($fs->exists($dir . '/meta.json'));
        $this->assertFalse($fs->exists($dir . '/lifecycle.jsonl'));
    }

    public function testTaskAgentRemovalOnlyChangesLiveRoster(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $parent = $this->make(Agent::class);

        $sub = $this->make(Agent::class, [
            'profileId' => 'lite',
            'parent' => $parent,
        ]);
        $leaf = $this->leafOf($sub);
        $this->registerChildForTest($parent, $sub);

        $fs = $this->container->get(FilesystemInterface::class);
        $dir = $this->container->get(PathAliasInterface::class)->resolve($sub->thread()->datadir);
        $this->assertStringContainsString('/subagents/', $dir);
        $this->assertMatchesRegularExpression('/^t[1-9]\d{4,6}$/', $this->leafOf($sub));
        $this->assertTrue($fs->isDir($dir));
        $this->assertTrue($fs->exists($dir . '/transcript.md'));
        $this->assertSame('', $fs->read($dir . '/transcript.md'));
        $this->assertArrayNotHasKey('subagents', $this->agentState($parent));
        $this->assertStringContainsString('.', $sub->thread()->id);

        $this->shutdownChildForTest($parent, $leaf);

        $this->assertArrayNotHasKey('subagents', $this->agentState($parent));
        $this->assertArrayNotHasKey($leaf, $this->liveChildren($parent));
    }
}
