<?php

declare(strict_types=1);

namespace Switon\Skill\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Skill\Skill;
use Switon\Skill\Skills;
use Switon\Skill\Tool\SkillListTool;
use Switon\Testing\TestCase;

#[CoversClass(SkillListTool::class)]
class SkillListToolTest extends TestCase
{
    public function testSchemaIsCachedAndCallIsReadonly(): void
    {
        $tool = $this->tool();

        $schema = $tool->schema('skill_list');
        $this->assertSame($schema, $tool->schema('skill_list'));
        $this->assertArrayNotHasKey('required', $schema->definition['inputSchema']);
        $this->assertTrue($tool->isReadonly('skill_list', []));
    }

    public function testCallListsEverySkillInIdOrder(): void
    {
        $rows = $this->tool()->call($this->agent(), new ToolCall(id: 'list', name: 'skill_list'), []);

        $this->assertSame([
            ['id' => 'alpha', 'description' => 'Alpha.'],
            ['id' => 'beta', 'description' => 'Writes PDF.'],
            ['id' => 'gamma', 'description' => 'Gamma desk.'],
        ], $rows);
    }

    public function testKeywordsMatchIdOrDescription(): void
    {
        $tool = $this->tool();
        $call = new ToolCall(id: 'list', name: 'skill_list');

        $this->assertSame(
            [['id' => 'beta', 'description' => 'Writes PDF.']],
            $tool->call($this->agent(), $call, ['keywords' => ['pdf']]),
        );
        $this->assertSame(
            [
                ['id' => 'alpha', 'description' => 'Alpha.'],
                ['id' => 'gamma', 'description' => 'Gamma desk.'],
            ],
            $tool->call($this->agent(), $call, ['keywords' => ['alpha', 'desk']]),
        );
        $this->assertSame([], $tool->call($this->agent(), $call, ['keywords' => ['missing']]));
    }

    protected function agent(): AgentInterface
    {
        return $this->createStub(AgentInterface::class);
    }

    protected function tool(): SkillListTool
    {
        $skills = new Skills();
        foreach ([
            ['id' => 'gamma', 'description' => 'Gamma desk.'],
            ['id' => 'alpha', 'description' => 'Alpha.'],
            ['id' => 'beta', 'description' => 'Writes PDF.'],
        ] as $row) {
            $skills->set(Skill::of($row));
        }

        return $this->make(SkillListTool::class, ['skills' => $skills]);
    }
}
