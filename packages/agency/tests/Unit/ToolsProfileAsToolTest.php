<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Profiles;
use Switon\Agency\ProfilesInterface;
use Switon\Agency\ChartersInterface;
use Switon\Agency\Tests\AgentContainerTestCase;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;

#[CoversClass(Tools::class)]
#[CoversClass(Profiles::class)]
class ToolsProfileAsToolTest extends AgentContainerTestCase
{
    public function testExposeAsToolWireBecomesTool(): void
    {
        /** @var ToolsInterface $tools */
        $tools = $this->container->get(ToolsInterface::class);

        $exposed = $tools->pick(['agent_file_research', 'agent_web_research']);

        $this->assertArrayHasKey('agent_file_research', $exposed);
        $this->assertArrayHasKey('agent_web_research', $exposed);
        $this->assertStringStartsWith(
            "[specialist worker] Delegate a self-contained goal; this tool returns the specialist's final answer.",
            $exposed['agent_file_research']->definition['description'],
        );
        $this->assertStringContainsString(
            'Read-only local file and directory search',
            $exposed['agent_file_research']->definition['description'],
        );
        $this->assertStringContainsString('No web, writes, or shell', $exposed['agent_file_research']->definition['description']);
        $this->assertStringContainsString('Never inspects local files', $exposed['agent_web_research']->definition['description']);
        $this->assertContains('message', $exposed['agent_file_research']->definition['inputSchema']['required']);
        $this->assertContains('.caption', $exposed['agent_file_research']->definition['inputSchema']['required']);
        $this->assertArrayHasKey('as_reply', $exposed['agent_file_research']->definition['inputSchema']['properties']);
    }

    public function testExposeOmitsUnknownAsToolWire(): void
    {
        /** @var ToolsInterface $tools */
        $tools = $this->container->get(ToolsInterface::class);

        $this->assertArrayNotHasKey('agent_missing', $tools->pick(['agent_missing']));
    }

    public function testHostExposeIncludesMountedAgentTools(): void
    {
        /** @var ChartersInterface $charters */
        $charters = $this->container->get(ChartersInterface::class);

        $tools = array_keys($charters->get($this->memoryAgent('host'))->tools);

        $this->assertContains('agent_file_research', $tools);
        $this->assertContains('agent_web_research', $tools);
        $this->assertContains('task_deliver', $tools);
    }
}
