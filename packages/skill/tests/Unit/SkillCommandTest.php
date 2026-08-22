<?php

declare(strict_types=1);

namespace Switon\Skill\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\ConsoleInterface;
use Switon\Skill\Command\SkillCommand;
use Switon\Skill\Skill;
use Switon\Skill\SkillsInterface;
use Switon\Testing\TestCase;

#[CoversClass(SkillCommand::class)]
class SkillCommandTest extends TestCase
{
    public function testListPrintsIdRootAndShortDescription(): void
    {
        $skills = $this->createMock(SkillsInterface::class);
        $skills->expects($this->once())->method('reload');
        $skills->method('list')->with(['pdf'])->willReturn([
            ['id' => 'beta', 'description' => 'Writes PDF. Then a long tail the table must not print in full.'],
        ]);
        $skills->method('get')->with('beta')->willReturn(Skill::of([
            'id' => 'beta',
            'path' => '/skills/beta',
        ]));

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('table')->with(
            ['id', 'path', 'description'],
            [['beta', '/skills/beta', 'Writes PDF. Then a long tail the table must not…']],
        );

        $code = $this->command($skills, $console)->listAction('pdf');

        $this->assertSame(0, $code);
    }

    public function testEmptyListSaysNone(): void
    {
        $skills = $this->createStub(SkillsInterface::class);
        $skills->method('list')->willReturn([]);

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('writeLn')->with('No skills.');
        $console->expects($this->never())->method('table');

        $this->assertSame(0, $this->command($skills, $console)->listAction());
    }

    public function testListKeepsAShortDescription(): void
    {
        $skills = $this->createStub(SkillsInterface::class);
        $skills->method('list')->willReturn([
            ['id' => 'beta', 'description' => 'Writes PDF.'],
        ]);
        $skills->method('get')->willReturn(Skill::of([
            'id' => 'beta',
            'path' => '/skills/beta',
        ]));

        $console = $this->createMock(ConsoleInterface::class);
        $console->expects($this->once())->method('table')->with(
            ['id', 'path', 'description'],
            [['beta', '/skills/beta', 'Writes PDF.']],
        );

        $this->assertSame(0, $this->command($skills, $console)->listAction('pdf'));
    }

    protected function command(SkillsInterface $skills, ConsoleInterface $console): SkillCommand
    {
        return $this->make(SkillCommand::class, [
            'skills' => $skills,
            'console' => $console,
        ]);
    }
}
