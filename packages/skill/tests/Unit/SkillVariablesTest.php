<?php

declare(strict_types=1);

namespace Switon\Skill\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Switon\Ai\AgentInterface;
use Switon\Ai\Profile;
use Switon\Skill\Exception;
use Switon\Skill\Skill;
use Switon\Skill\Skills;

#[CoversClass(Skills::class)]
class SkillVariablesTest extends TestCase
{
    public function testFiltersAdvertisedSkills(): void
    {
        $skills = $this->skills([
            ['id' => 'alpha', 'description' => 'Alpha.'],
            ['id' => 'beta', 'description' => 'Beta.'],
            ['id' => 'gamma', 'description' => 'Gamma.'],
        ]);
        $profile = Profile::decode([
            'id' => 'host',
            'skills' => ['alpha', 'gamma'],
        ]);

        $data = $skills->variables($this->agent($profile));

        $this->assertSame(['alpha', 'gamma'], array_column($data['skills'], 'id'));
        $this->assertArrayNotHasKey('all_skills', $data);
    }

    public function testNoAdvertisedSkillsHidesCatalog(): void
    {
        $skills = $this->skills([
            ['id' => 'alpha', 'description' => 'Alpha.'],
            ['id' => 'beta', 'description' => 'Beta.'],
            ['id' => 'gamma', 'description' => 'Gamma.'],
        ]);

        $data = $skills->variables($this->agent(Profile::decode(['id' => 'host'])));

        $this->assertSame([], $data['skills']);
    }

    public function testDottedProfileIdAutoAdvertisesPrefixedSkills(): void
    {
        $skills = $this->skills([
            ['id' => 'alpha', 'description' => 'A'],
            ['id' => 'investor.risk', 'description' => 'R'],
            ['id' => 'investor.tip', 'description' => 'T'],
            ['id' => 'coding.x', 'description' => 'C'],
        ]);
        $profile = Profile::decode([
            'id' => 'investor.chat',
            'skills' => ['alpha'],
        ]);

        $data = $skills->variables($this->agent($profile));

        $this->assertSame(
            ['alpha', 'investor.risk', 'investor.tip'],
            array_column($data['skills'], 'id'),
        );
    }

    public function testDottedProfileWithNullSkillsStillAutoAdvertisesPrefix(): void
    {
        $profile = Profile::decode(['id' => 'investor.chat']);
        $this->assertNull($profile->skills);
        $skills = $this->skills([
            ['id' => 'investor.risk', 'description' => 'R'],
            ['id' => 'coding.x', 'description' => 'C'],
        ]);

        $data = $skills->variables($this->agent($profile));

        $this->assertSame(['investor.risk'], array_column($data['skills'], 'id'));
    }

    public function testDottedProfileWithEmptySkillsOptsOutOfAutoPrefix(): void
    {
        $profile = Profile::decode([
            'id' => 'investor.chat',
            'skills' => [],
        ]);
        $skills = $this->skills([
            ['id' => 'investor.risk', 'description' => 'R'],
            ['id' => 'alpha', 'description' => 'A'],
        ]);

        $data = $skills->variables($this->agent($profile));

        $this->assertSame([], $data['skills']);
    }

    public function testSkipsBlankIdsAndRejectsNonString(): void
    {
        $skills = $this->skills([
            ['id' => 'alpha', 'description' => 'Alpha.'],
        ]);
        $profile = Profile::decode([
            'id' => 'host',
            'skills' => ['', '  ', 'alpha'],
        ]);

        $data = $skills->variables($this->agent($profile));

        $this->assertSame(['alpha'], array_column($data['skills'], 'id'));

        $profile->skills = [1];
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('non-string skill id');
        $skills->variables($this->agent($profile));
    }

    public function testLeadingDotProfileDoesNotTreatDotAsPrefix(): void
    {
        $skills = $this->skills([
            ['id' => 'alpha', 'description' => 'A'],
            ['id' => 'coding.x', 'description' => 'C'],
        ]);
        $profile = Profile::decode([
            'id' => '.chat',
            'skills' => ['alpha'],
        ]);

        $data = $skills->variables($this->agent($profile));

        $this->assertSame(['alpha'], array_column($data['skills'], 'id'));
    }

    public function testRejectsUnknownAdvertisedSkill(): void
    {
        $skills = $this->skills([
            ['id' => 'alpha', 'description' => 'Alpha.'],
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('unknown skill "missing"');
        $skills->variables($this->agent(Profile::decode(['id' => 'host', 'skills' => ['missing']])));
    }

    protected function agent(Profile $profile): AgentInterface
    {
        $agent = $this->createStub(AgentInterface::class);
        $agent->method('profile')->willReturn($profile);

        return $agent;
    }

    /**
     * @param list<array{id: string, description: string}> $rows
     */
    protected function skills(array $rows): Skills
    {
        $skills = new Skills();
        foreach ($rows as $row) {
            $skills->set(Skill::of($row));
        }

        return $skills;
    }
}
