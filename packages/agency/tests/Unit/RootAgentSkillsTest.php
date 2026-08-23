<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Agent;
use Switon\Ai\Thread;

#[CoversClass(Agent::class)]
#[Group('coroutine')]
class RootAgentSkillsTest extends TestCase
{
    public function testSetSkillsPersistsInExportState(): void
    {
        $manager = $this->container->get(RecordingAgentManager::class);
        $host = $manager->mint([]);

        $applied = ['switon-sample', 'app-demo'];
        $state = $this->agentState($host);
        $state['skills'] = $applied;
        $host->thread()->state = $state;
        $this->persistAgentState($host);

        $this->assertSame(['switon-sample', 'app-demo'], $applied);
        $this->assertSame(['switon-sample', 'app-demo'], $this->agentState($host)['skills']);

        $state = $this->loadThread($host->thread()->datadir, $host->thread()->id)->state;
        $this->assertSame(['switon-sample', 'app-demo'], $state['skills']);

        $restored = $manager->mint([
            'thread' => $host->thread(),
            'state' => $state,
        ]);
        $this->assertSame(['switon-sample', 'app-demo'], $this->agentState($restored)['skills']);
    }
}
