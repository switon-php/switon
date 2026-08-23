<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Tests\Fixtures\RecordingAgentManager;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Charters;
use Switon\Agency\Exception;

#[CoversClass(Charters::class)]
#[Group('coroutine')]
class ChartersTest extends TestCase
{
    public function testBuildIncludesSystemPromptWithoutSummary(): void
    {
        $host = $this->container->get(RecordingAgentManager::class)->mint([]);

        $charter = $host->charter();

        $this->assertNotSame([], $charter->tools);
        $this->assertNotSame('', $charter->prompt);
    }

    public function testCharterIsStableUntilInvalidated(): void
    {
        $host = $this->container->get(RecordingAgentManager::class)->mint([]);

        $first = $host->charter();
        $this->assertSame($first, $host->charter(), 'reads must not rebuild while fresh');

        $first->invalidate();
        $second = $host->charter();
        $this->assertNotSame($first, $second, 'an invalidated charter is replaced on the next read');
        $this->assertFalse($second->invalidated());
    }

    public function testCharterDoesNotEmbedLoadedSkillBodies(): void
    {
        $host = $this->container->get(RecordingAgentManager::class)->mint([]);
        $state = $this->agentState($host);
        $state['skills'] = ['switon-sample'];
        $host->thread()->state = $state;

        $prompt = $host->charter()->prompt;

        $this->assertStringNotContainsString('Use Switon package rules.', $prompt);
        $this->assertStringNotContainsString('Active skills', $prompt);
    }

    public function testSkillsStateChangeDoesNotRebuildCharterPayload(): void
    {
        $host = $this->container->get(RecordingAgentManager::class)->mint([]);

        $before = $host->charter()->prompt;
        $state = $this->agentState($host);
        $state['skills'] = ['switon-sample'];
        $host->thread()->state = $state;
        $after = $host->charter()->prompt;

        $this->assertSame($before, $after);
    }

    public function testCharterAdvertisesOnlyProfileSkillsAndSearchFallback(): void
    {
        $this->ensureTools('skill_list');
        $host = $this->container->get(RecordingAgentManager::class)->mint([]);
        $prompt = $host->charter()->prompt;

        $this->assertArrayHasKey('skill_list', $host->charter()->tools);
        $this->assertArrayNotHasKey('agent_skill_search', $host->charter()->tools);
        $this->assertStringContainsString('Call `skill_list`', $prompt);
        $this->assertStringContainsString('one for a focused task, several for distinct facets', $prompt);
        $this->assertStringContainsString('call `skill_load` with its `id`', $prompt);
        $this->assertStringNotContainsString('## Advertised skills', $prompt);
        $this->assertGreaterThan(
            strpos($prompt, 'Call `skill_list`'),
            strpos($prompt, 'Date:'),
        );
    }

    public function testCharterIncludesMcpGatewayFromSegment(): void
    {
        $this->ensureTools('mcp_servers', 'mcp_tools', 'mcp_schema', 'mcp_call');
        $host = $this->container->get(RecordingAgentManager::class)->mint([]);

        $charter = $host->charter();

        $this->assertArrayHasKey('mcp_call', $charter->tools);
        $this->assertArrayHasKey('mcp_servers', $charter->tools);
        $this->assertArrayHasKey('mcp_tools', $charter->tools);
        $this->assertArrayHasKey('mcp_schema', $charter->tools);
        $this->assertStringContainsString('## MCP', $charter->prompt);
        $this->assertArrayNotHasKey('demo_ping', $charter->tools);
    }

    public function testDelegatedCharterDoesNotInheritMcpSegment(): void
    {
        $this->ensureTools('mcp_call', 'media');
        $host = $this->container->get(RecordingAgentManager::class)->mint([]);
        $child = $this->spawnDelegated($host, 'lite');

        $this->assertArrayHasKey('mcp_call', $host->charter()->tools);
        $this->assertArrayNotHasKey('mcp_call', $child->charter()->tools);
        $this->assertArrayHasKey('media', $host->charter()->tools);
        $this->assertArrayNotHasKey('media', $child->charter()->tools);
        $this->assertNotNull($child->parent());
    }

    public function testSiteSegmentGrantsToolsAndRendersRegistry(): void
    {
        $this->ensureTools('site_search', 'site_list');
        $host = $this->container->get(RecordingAgentManager::class)->mint([]);
        $profile = clone $host->profile();
        $profile->segments = [...$profile->segments, 'site'];
        (new \ReflectionProperty($host, 'profile'))->setValue($host, $profile);

        $charter = $host->charter();

        $this->assertArrayHasKey('site_search', $charter->tools);
        $this->assertArrayHasKey('site_list', $charter->tools);
        $this->assertStringContainsString('Curated registry', $charter->prompt);
    }

    public function testCharterRejectsUnknownExtraSegment(): void
    {
        $host = $this->container->get(RecordingAgentManager::class)->mint([]);
        $profile = clone $host->profile();
        $profile->segments = [...$profile->segments, 'missing'];
        (new \ReflectionProperty($host, 'profile'))->setValue($host, $profile);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Segment "missing" not found.');
        $host->charter();
    }
}
