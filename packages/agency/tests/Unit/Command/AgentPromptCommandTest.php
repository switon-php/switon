<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Command\AgentCommand;
use Switon\Agency\Tests\AgentContainerTestCase;
use Switon\Ai\Segment;
use Switon\Agency\SegmentsInterface;
use Switon\Core\ConsoleInterface;
use Switon\Testing\Mock\MockConsole;

#[CoversClass(AgentCommand::class)]
class AgentPromptCommandTest extends AgentContainerTestCase
{
    public function testPromptRendersComposedProfileToolsAndBody(): void
    {
        $command = $this->container->get(AgentCommand::class);

        $code = $command->promptAction('file-researcher');

        $this->assertSame(ConsoleInterface::SUCCESS, $code);
        $out = $this->consoleOutput();
        $this->assertStringStartsWith('## Tools', $out);
        $this->assertLessThan(strpos($out, 'Read-only.'), strpos($out, '## Tools'));
        $this->assertStringContainsString('- read', $out);
        $this->assertStringContainsString('- grep', $out);
        $this->assertStringContainsString('Read-only.', $out);
        $this->assertStringContainsString('## Workspace context', $out);
        $this->assertStringNotContainsString('## Prompt', $out);
        // Root depth: root-only segment rules render.
        $this->assertStringContainsString('KaTeX math is available', $out);
    }

    public function testExtraSegmentIsAppendedToTheRenderedPrompt(): void
    {
        $segments = $this->container->get(SegmentsInterface::class);
        $segments->set(Segment::decode(['id' => 'debug.note', 'prompt' => 'SEGMENT-MARKER']));
        $command = $this->container->get(AgentCommand::class);

        // Extra segments join the render.
        $code = $command->promptAction('text', ['debug.note']);

        $this->assertSame(ConsoleInterface::SUCCESS, $code);
        $out = $this->consoleOutput();
        $this->assertStringStartsWith('## Tools', $out);
        $this->assertStringContainsString('SEGMENT-MARKER', $out);
    }

    public function testUnknownProfileFails(): void
    {
        $command = $this->container->get(AgentCommand::class);

        $code = $command->promptAction('missing-profile');

        $this->assertSame(ConsoleInterface::FAILURE, $code);
        $this->assertStringContainsString('missing-profile', $this->consoleOutput());
    }

    protected function consoleOutput(): string
    {
        return implode("\n", $this->container->get(MockConsole::class)->getOutput());
    }
}
