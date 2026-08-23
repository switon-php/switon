<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use ArrayIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Agent;
use Switon\Ai\Event\DeliveryStarted;
use Switon\Agency\Tests\Fixtures\StubTool;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message\UserMessage;
use Switon\Testing\EventDispatcher as TestEventDispatcher;

#[CoversClass(Agent::class)]
#[Group('coroutine')]
class AgentToolInheritanceTest extends TestCase
{
    public function testSubAgentUsesOwnConfiguredTools(): void
    {
        $this->container->replace(ToolsInterface::class, [
            'class' => Tools::class,
            'tools' => [
                'bash' => StubTool::config('bash', 'Shell'),
                'read' => StubTool::config('read', 'Read'),
                'grep' => StubTool::config('grep', 'Grep'),
                ...$this->builtinToolConfig(),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]);

        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('done');
        $this->container->set(LlmInterface::class, $stub);

        $host = $this->make(Agent::class, []);
        $child = $this->spawnDelegated($host, 'probe');
        $this->deliverSubForTest($child, UserMessage::of('probe'));

        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);
        $started = array_values(array_filter(
            $dispatcher->getEventsByClass(DeliveryStarted::class),
            static fn (DeliveryStarted $event): bool => $event->sender->threadId() === $child->threadId(),
        ));
        $this->assertCount(1, $started);
        $charter = $started[0]->sender->charter();
        $ordinary = array_values(array_filter(
            array_keys($charter->tools),
            static fn (string $name): bool => !str_starts_with($name, 'task_')
                && $name !== 'recall',
        ));
        $this->assertEqualsCanonicalizing(
            ['bash', 'grep', 'read'],
            $ordinary,
        );
    }
}
