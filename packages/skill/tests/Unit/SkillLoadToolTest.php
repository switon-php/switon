<?php

declare(strict_types=1);

namespace Switon\Skill\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\AgentInterface;
use Switon\Ai\Exception\ToolInvalidArgumentException;
use Switon\Ai\Part\PinnedPart;
use Switon\Ai\ToolCall;
use Switon\Skill\Skill;
use Switon\Skill\Skills;
use Switon\Skill\Tool\SkillLoadTool;
use Switon\Testing\TestCase;

#[CoversClass(SkillLoadTool::class)]
class SkillLoadToolTest extends TestCase
{
    public function testSchemaIsCachedAndCallIsReadonly(): void
    {
        $tool = $this->tool();

        $schema = $tool->schema('skill_load');
        $this->assertSame($schema, $tool->schema('skill_load'));
        $this->assertSame(['id'], $schema->definition['inputSchema']['required']);
        $this->assertTrue($tool->isReadonly('skill_load', ['id' => 'demo']));
    }

    public function testCallPinsTheBlock(): void
    {
        $result = $this->tool()->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 'call_load', name: 'skill_load'),
            ['id' => 'demo'],
        );

        $this->assertSame('', $result->content);
        $this->assertInstanceOf(PinnedPart::class, $result->parts[0]);
        $this->assertSame('skill:demo', $result->parts[0]->id);
        $this->assertStringContainsString('Do the thing.', $result->parts[0]->text);
    }

    public function testCallRejectsUnknownId(): void
    {
        $this->expectException(ToolInvalidArgumentException::class);
        $this->tool()->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 'call_missing', name: 'skill_load'),
            ['id' => 'missing'],
        );
    }

    protected function tool(): SkillLoadTool
    {
        $skills = new Skills();
        $skills->set(Skill::of([
            'id' => 'demo',
            'description' => 'Demo',
            'instructions' => 'Do the thing.',
            'path' => '/skills/demo',
        ]));

        return $this->make(SkillLoadTool::class, ['skills' => $skills]);
    }
}
