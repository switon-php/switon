<?php

declare(strict_types=1);

namespace Switon\Thread\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Thread;
use Switon\Core\FilesystemInterface;
use Switon\Thread\Tests\TestCase;
use Switon\Thread\Threads;

#[CoversClass(Threads::class)]
class ThreadLifecycleTest extends TestCase
{
    public function testCreateMaterializesLeafWithoutConversationMeta(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $threadId = 'host-1';
        $this->threadStore()->create(Thread::of($threadId, '@threads/' . $threadId));
        $fs = $this->container->get(FilesystemInterface::class);
        $dir = $this->threadLeafDir($threadId);

        $this->assertTrue($fs->isDir($dir));
        $this->assertTrue($fs->exists($dir . '/transcript.md'));
        $this->assertSame('', $fs->read($dir . '/transcript.md'));
        $this->assertTrue($fs->exists($dir . '/state.json'));
        $this->assertSame([], \Switon\Core\Json::parse($fs->read($dir . '/state.json')));
        $this->assertFalse($fs->exists($dir . '/conversation.jsonl'));
        $this->assertFalse($fs->exists($dir . '/meta.json'));
        $this->assertFalse($fs->exists($dir . '/lifecycle.jsonl'));
    }
}
