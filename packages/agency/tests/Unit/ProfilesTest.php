<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Agency\ProfileDiscovery;
use Switon\Agency\Profiles;
use Switon\Agency\Segments;
use Switon\Agency\ProfilesInterface;
use Switon\Agency\ChartersInterface;
use Switon\Agency\Toolkit\AsToolToolkit;
use Switon\Agency\Tests\AgentContainerTestCase;
use Switon\Agency\Tests\Fixtures\EchoTool;
use Switon\Tooling\ToolsInterface;

use function file_put_contents;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(Profiles::class)]
class ProfilesTest extends AgentContainerTestCase
{
    public function testScansFixtureAgents(): void
    {
        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);

        foreach (['assist', 'lite', 'file-researcher', 'host'] as $name) {
            $this->assertTrue($profiles->has($name), $name);
        }

        $general = $profiles->all()['assist'];
        $this->assertSame('assist', $general->id);

        $this->assertTrue($profiles->has('family.child'));
        $this->assertSame('family.child', $profiles->all()['family.child']->id);
        $this->assertTrue($profiles->has('pinned'));
        $this->assertFalse($profiles->has('family.pinned'));
        $this->assertFalse($profiles->has('skip'), 'dirs starting with _ are skipped');
        $this->assertFalse($profiles->has('_ignored.skip'));
        $this->assertFalse($profiles->has('README'));
        $this->assertFalse($profiles->has('readme'));
    }

    public function testPreviewComposesSegmentsAndTools(): void
    {
        /** @var ChartersInterface $charters */
        $charters = $this->container->get(ChartersInterface::class);

        $charter = $charters->get($this->memoryAgent('all'));
        $tools = array_keys($charter->tools);
        $this->assertContains('echo', $tools);
        $this->assertContains('grep', $tools);
        $this->assertStringContainsString('## Response style', $charter->prompt);
    }

    public function testPreviewHostIncludesTaskDeliverFromSegment(): void
    {
        /** @var ChartersInterface $charters */
        $charters = $this->container->get(ChartersInterface::class);

        $this->presentTools('skill_load', 'skill_run');
        $charter = $charters->get($this->memoryAgent('host'));
        $tools = array_keys($charter->tools);
        $this->assertContains('task_deliver', $tools);
        foreach (['skill_load', 'read', 'ls', 'glob', 'skill_run'] as $tool) {
            $this->assertContains($tool, $tools, $tool);
        }
        $this->assertStringContainsString('orchestrator', $charter->prompt);
    }

    public function testAsToolProfilesHaveMountWire(): void
    {
        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);

        $this->assertSame('agent_lite', $profiles->all()['lite']->asTool);
        $this->assertSame('agent_file_research', $profiles->all()['file-researcher']->asTool);
        $this->assertSame('', $profiles->all()['host']->asTool);
    }

    public function testAsToolSchema(): void
    {
        $toolkit = $this->container->get(AsToolToolkit::class);

        $this->assertNotNull($toolkit->schema('agent_file_research'));
        $schema = $toolkit->schema('file_research');
        $this->assertStringStartsWith('[specialist worker] ', $schema->definition['description']);
        $this->assertStringContainsString('Read-only', $schema->definition['description']);
        $this->assertEquals($schema, $toolkit->schema('agent_file_research'));

        // Unclaimed wires resolve to null (the toolkit does not claim them).
        $this->assertNull($toolkit->schema('file-researcher'));
        $this->assertNull($toolkit->schema('agent_'));
    }

    public function testPreviewHostIncludesProfileMounts(): void
    {
        /** @var ChartersInterface $charters */
        $charters = $this->container->get(ChartersInterface::class);

        $tools = array_keys($charters->get($this->memoryAgent('host'))->tools);
        $this->assertContains('agent_file_research', $tools);
        $this->assertContains('task_deliver', $tools);
    }

    public function testSelectableFlagOnDefaultProfiles(): void
    {
        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);

        // as_tool workers declare selectable=false (picker hides them).
        $this->assertFalse($profiles->get('lite')->selectable);
        $this->assertTrue($profiles->get('host')->selectable);
        $this->assertFalse($profiles->get('assist')->selectable);
        $this->assertFalse($profiles->has('missing'));
    }

    public function testInternalThreadUtilityProfilesAreRegistered(): void
    {
        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);

        foreach (['aside', 'instructor'] as $id) {
            $this->assertTrue($profiles->has($id), $id);
            $this->assertFalse($profiles->get($id)->selectable, $id);
            $this->assertSame('', $profiles->all()[$id]->asTool, $id);
            $this->assertStringNotContainsString('{% if', $profiles->get($id)->prompt);
        }
    }

    public function testExtraAgentRootPublishesItsSegment(): void
    {
        $root = sys_get_temp_dir() . '/switon-agents-extra-' . uniqid('', true);
        mkdir($root . '/segments', 0777, true);
        file_put_contents($root . '/segments/from-extra.md', <<<'MD'
---
description: From extra.
---
Body.
MD);
        $extra = $this->createStub(ComposerExtraInterface::class);
        $extra->method('collect')->willReturnCallback(
            static fn (string $key): array => $key === 'switon.agents' ? [$root] : [],
        );

        try {
            $segments = $this->container->make(Segments::class);
            $profiles = $this->container->make(Profiles::class, [
                'segments' => $segments,
            ]);
            $discovery = $this->container->make(ProfileDiscovery::class, [
                'paths' => [],
                'profiles' => $profiles,
                'segments' => $segments,
                'composerExtra' => $extra,
            ]);
            $discovery->discover();

            $this->assertTrue($segments->has('from-extra'));
            $this->assertStringContainsString('Body.', $segments->get('from-extra')->prompt);
        } finally {
            unlink($root . '/segments/from-extra.md');
            rmdir($root . '/segments');
            rmdir($root);
        }
    }

    public function testGetExpandsOwnGrantWithRegistrySegments(): void
    {
        /** @var ProfilesInterface $profiles */
        $profiles = $this->container->get(ProfilesInterface::class);

        $expanded = $profiles->get('grant');
        $this->assertContains('read', $expanded->tools);
        $this->assertStringContainsString('Grant body.', $expanded->prompt);
    }

    /**
     * Skill tools live in switon/skill. A stand-in is enough to see the segment grant.
     */
    protected function presentTools(string ...$names): void
    {
        $tools = $this->container->get(ToolsInterface::class);
        $standIn = new EchoTool();
        foreach ($names as $name) {
            $tools->addTool($name, $standIn);
        }
    }
}
