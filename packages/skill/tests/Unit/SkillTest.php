<?php

declare(strict_types=1);

namespace Switon\Skill\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Switon\Skill\Skill;

#[CoversClass(Skill::class)]
class SkillTest extends TestCase
{
    public function testOfMapsStringFields(): void
    {
        $skill = Skill::of([
            'id' => 'demo',
            'description' => 'Demo skill',
            'instructions' => 'Do the thing.',
            'path' => '/skills/demo',
        ]);

        $this->assertSame('demo', $skill->id);
        $this->assertSame('Demo skill', $skill->description);
        $this->assertSame('Do the thing.', $skill->instructions);
        $this->assertSame('/skills/demo', $skill->path);
    }

    public function testOfDefaultsMissingFields(): void
    {
        $skill = Skill::of([]);

        $this->assertSame('', $skill->id);
        $this->assertSame('', $skill->description);
        $this->assertSame('', $skill->instructions);
        $this->assertSame('', $skill->path);
    }
}
