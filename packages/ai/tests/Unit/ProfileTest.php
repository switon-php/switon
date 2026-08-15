<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Profile;
use Switon\Ai\Tests\TestCase;

#[CoversClass(Profile::class)]
class ProfileTest extends TestCase
{
    public function testSelectableDefaultsTrueForHostProfiles(): void
    {
        $profile = new Profile(id: 'main');

        $this->assertTrue($profile->selectable);
    }

    public function testSelectableIgnoresAsToolMount(): void
    {
        // Mounting as a tool does not hide a profile from the picker; declare `selectable: false` for that.
        $profile = new Profile(id: 'worker', asTool: 'agent_worker');

        $this->assertTrue($profile->selectable);
    }

    public function testDecodeMapsCatalogRow(): void
    {
        $profile = Profile::decode([
            'id' => 'research',
            'description' => 'Web research',
            'model' => 'best',
            'prompt' => 'Research carefully.',
            'tools' => ['web_search'],
            'segments' => ['core', '', 'mcp'],
            'skills' => ['demo'],
            'max_steps' => 8,
            'as_tool' => 'agent_research',
            'selectable' => true,
            'icon' => 'search',
            'order' => 5,
        ]);

        $this->assertSame('research', $profile->id);
        $this->assertSame('Web research', $profile->description);
        // `model` is opaque to `ai` — the catalog loader decides what an empty value means.
        $this->assertSame('best', $profile->model);
        // Keys pass through as data; an empty segment id fails loudly at `Profiles::get()`.
        $this->assertSame(['core', '', 'mcp'], $profile->segments);
        $this->assertSame(['demo'], $profile->skills);
        $this->assertSame(8, $profile->maxSteps);
        $this->assertSame('agent_research', $profile->asTool);
        $this->assertTrue($profile->selectable);
        $this->assertSame('search', $profile->icon);
        $this->assertSame(5, $profile->order);
        $this->assertSame('Research carefully.', $profile->prompt);
        $this->assertSame(['web_search'], $profile->tools);
    }

    public function testDecodePassesToolsThroughWithoutNormalization(): void
    {
        // Tools are raw data; trim and dedupe happen when a charter is assembled.
        $profile = Profile::decode([
            'id' => 'host',
            'prompt' => 'Static',
            'tools' => ['echo', 'echo'],
        ]);

        $this->assertSame('Static', $profile->prompt);
        $this->assertSame(['echo', 'echo'], $profile->tools);
        $this->assertNull($profile->skills);
        $this->assertTrue($profile->selectable);
    }

    public function testDecodeTreatsEmptySkillsListAsAdvertiseNone(): void
    {
        $profile = Profile::decode([
            'id' => 'quiet',
            'skills' => [],
        ]);

        $this->assertSame([], $profile->skills);
    }
}
