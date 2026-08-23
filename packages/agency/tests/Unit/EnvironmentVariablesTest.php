<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\EnvironmentVariables;
use Switon\Agency\Tests\AgentContainerTestCase;
use Switon\Ai\AgentInterface;

#[CoversClass(EnvironmentVariables::class)]
class EnvironmentVariablesTest extends AgentContainerTestCase
{
    public function testWorkspaceFollowsEachAgentAcrossCalls(): void
    {
        $variables = $this->container->get(EnvironmentVariables::class);
        $first = $this->agentWithWorkspace('/first');
        $second = $this->agentWithWorkspace('/second');

        $this->assertSame('/first', $variables->variables($first)['env']['workspace']);
        $this->assertSame('/second', $variables->variables($second)['env']['workspace']);
        $this->assertSame('/first', $variables->variables($first)['env']['workspace']);
    }

    public function testProcessConstantsSurviveRepeatedCalls(): void
    {
        $variables = $this->container->get(EnvironmentVariables::class);
        $agent = $this->agentWithWorkspace('/workspace');

        $first = $variables->variables($agent)['env'];
        $second = $variables->variables($agent)['env'];

        $this->assertSame(PHP_OS_FAMILY, $second['os']);
        $this->assertSame(PHP_VERSION, $second['php']);
        $this->assertSame(array_keys($first), array_keys($second), 'Key order stays stable across calls.');
    }

    public function testEmptyWorkspaceStaysEmpty(): void
    {
        $variables = $this->container->get(EnvironmentVariables::class);

        $env = $variables->variables($this->agentWithWorkspace(''))['env'];

        $this->assertSame('', $env['workspace']);
        $this->assertArrayNotHasKey('cwd', $env);
    }

    protected function agentWithWorkspace(string $workspace): AgentInterface
    {
        $agent = $this->createStub(AgentInterface::class);
        $agent->method('workspace')->willReturn($workspace);

        return $agent;
    }
}
