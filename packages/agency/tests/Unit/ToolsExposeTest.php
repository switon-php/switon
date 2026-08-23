<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Agent;
use Switon\Agency\Tests\Fixtures\TestAgent;
use Switon\Agency\Tests\Fixtures\StubTool;
use Switon\Agency\Tests\TestCase;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;
use Switon\Ai\ToolSchema;

#[CoversClass(Tools::class)]
#[Group('coroutine')]
class ToolsExposeTest extends TestCase
{
    protected function tools(): ToolsInterface
    {
        return $this->container->get(ToolsInterface::class);
    }

    protected function bindTools(Tools $toolsRegistry): void
    {
        $this->container->replace(ToolsInterface::class, $toolsRegistry);
    }

    /** @return array<string, ToolSchema> */
    protected function exposed(Agent $agent): array
    {
        return $this->tools()->pick(
            $this->agentProp($agent, 'profileTools'),
        );
    }

    public function testExposeIncludesProfileTools(): void
    {
        $this->bindTools($this->make(Tools::class, [
            'tools' => ['echo' => StubTool::config('echo', 'Echo', static fn (): string => 'pong')],
        ]));

        $agent = $this->make(TestAgent::class, [
            'profile' => $this->testProfile(tools: ['echo']),
            ]);

        $this->assertSame(['echo'], array_keys($this->exposed($agent)));
    }

    public function testExposeInjectsCaptionSchema(): void
    {
        $this->bindTools($this->make(Tools::class, [
            'tools' => ['echo' => StubTool::config('echo', 'Echo', static fn (): string => 'pong')],
        ]));

        $agent = $this->make(TestAgent::class, [
            'profile' => $this->testProfile(tools: ['echo']),
            ]);

        $exposed = $this->exposed($agent);

        $schema = $exposed['echo']->definition['inputSchema']['properties']['.caption'];
        $this->assertSame('string', $schema['type']);
        $this->assertSame(32, $schema['maxLength']);
        $this->assertContains('.caption', $exposed['echo']->definition['inputSchema']['required']);
    }
}
