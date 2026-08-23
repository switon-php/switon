<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Agent;
use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Agency\Tests\TestCase;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Thread;
use Switon\Thread\Exception\ThreadNotFoundException;

#[CoversClass(Agent::class)]
#[Group('coroutine')]
class RootAgentSessionTest extends TestCase
{
    public function testCreateMintsThreadIdAndPersists(): void
    {
        $manager = $this->container->get(RecordingAgentManager::class);
        $agent = $manager->mint([]);

        $this->assertNotSame('', $agent->threadId());
        $this->threadStore()->load(Thread::of($agent->thread()->id, $agent->thread()->datadir));
    }

    public function testResumeRequiresExistingThread(): void
    {
        $this->expectException(ThreadNotFoundException::class);
        $this->startRoot('@threads/missing-thread');
    }

    public function testResumeRestoresPersistedState(): void
    {
        $manager = $this->container->get(RecordingAgentManager::class);
        $seed = $manager->mint([]);
        $seed->thread()->state['cursor'] = 6;
        $this->persistAgentState($seed);
        $this->threadAppend($seed->thread(), Completion::of(UserMessage::of('seed'), new AssistantMessage('ok')));

        $agent = $this->startRoot($seed->thread()->datadir);

        $this->assertSame(6, $agent->thread()->state['cursor']);
    }
}
