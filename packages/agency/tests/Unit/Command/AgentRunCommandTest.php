<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Agent;
use Switon\Agency\Command\AgentCommand;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Core\ConsoleInterface;
use Switon\Ai\Completion;
use Switon\Ai\Exception\InvalidToolArgumentsException;
use Switon\Ai\LlmInterface;
use Switon\Ai\Envelope;
use Switon\Testing\Mock\MockConsole;
use Switon\Ai\ToolCall;

#[CoversClass(AgentCommand::class)]
class AgentRunCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->container->set(AgentCommand::class, StderrCapturingAgentCommand::class);
    }

    public function testRunPrintsOnlyTheFinalReply(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('FINAL-REPLY');
        $this->container->set(LlmInterface::class, $stub);
        $command = $this->command();

        $code = $command->runAction('file-researcher', 'hello');

        $this->assertSame(ConsoleInterface::SUCCESS, $code);
        $this->assertSame(['FINAL-REPLY'], $this->stdout());
        $this->assertCount(1, $command->stderr);
        $this->assertStringStartsWith('thread → ', $command->stderr[0]);
    }

    public function testRunSucceedsWhenInvalidToolArgumentsRetryWorks(): void
    {
        $stub = new StubLlm();
        $stub->throwOnce = new InvalidToolArgumentsException(
            'bad json',
            new ToolCall('call_1', 'glob', []),
            '{"glob_pattern":"x"',
        );
        $stub->responses[] = StubLlm::assistant('FIXED');
        $this->container->set(LlmInterface::class, $stub);
        $command = $this->command();

        $code = $command->runAction('file-researcher', 'hello');

        $this->assertSame(ConsoleInterface::SUCCESS, $code);
        $this->assertSame(['FIXED'], $this->stdout());
        $this->assertStringStartsWith('thread → ', $command->stderr[0]);
    }

    public function testRunFailsWhenInvalidToolArgumentsRetryExhausted(): void
    {
        $bad = static fn (): InvalidToolArgumentsException => new InvalidToolArgumentsException(
            'bad json',
            new ToolCall('call_1', 'glob', []),
            '{"glob_pattern":"x"',
        );
        $stub = new StubLlm();
        $stub->throwQueue = [$bad(), $bad()];
        $this->container->set(LlmInterface::class, $stub);
        $command = $this->command();

        $code = $command->runAction('file-researcher', 'hello');

        $this->assertSame(ConsoleInterface::FAILURE, $code);
        $this->assertSame([], $this->stdout());
        $this->assertStringContainsString('bad json', $command->stderr[0]);
        $this->assertStringStartsWith('thread → ', $command->stderr[1]);
    }

    public function testRunContinuesExistingThread(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('FIRST');
        $stub->responses[] = StubLlm::assistant('SECOND');
        $this->container->set(LlmInterface::class, $stub);
        $agent = $this->make(Agent::class, ['profileId' => 'file-researcher']);
        $agent->run(Envelope::of('first', $agent->profile()->model));
        $threadId = $agent->threadId();
        $agent->shutdown();
        $command = $this->command();

        $code = $command->runAction(message: 'second', threadId: $threadId);

        $this->assertSame(ConsoleInterface::SUCCESS, $code);
        $this->assertSame(['SECOND'], $this->stdout());
        $this->assertSame(['thread → ' . $threadId], $command->stderr);
        $inputs = array_map(
            static fn (Completion $c): string => $c->input->wire()[0]['text'],
            $this->threadTranscript($threadId),
        );
        $this->assertContains('first', $inputs);
        $this->assertContains('second', $inputs);
    }

    public function testRunFailsOnUnknownThread(): void
    {
        $command = $this->command();

        $code = $command->runAction(message: 'hello', threadId: 'missing-thread');

        $this->assertSame(ConsoleInterface::FAILURE, $code);
        $this->assertSame([], $this->stdout());
        $this->assertStringContainsString('missing-thread', implode("\n", $command->stderr));
    }

    public function testRunRequiresMessage(): void
    {
        $command = $this->command();

        $code = $command->runAction('file-researcher', '  ');

        $this->assertSame(ConsoleInterface::FAILURE, $code);
        $this->assertSame([], $this->stdout());
        $this->assertSame(['--message is required.'], $command->stderr);
    }

    public function testRunRejectsUnknownAgent(): void
    {
        $command = $this->command();

        $code = $command->runAction('missing-profile', 'hello');

        $this->assertSame(ConsoleInterface::FAILURE, $code);
        $this->assertSame([], $this->stdout());
        $this->assertStringContainsString('missing-profile', implode("\n", $command->stderr));
    }

    protected function command(): StderrCapturingAgentCommand
    {
        return $this->container->get(AgentCommand::class);
    }

    /** @return list<string> */
    protected function stdout(): array
    {
        return $this->container->get(MockConsole::class)->getOutput();
    }
}

class StderrCapturingAgentCommand extends AgentCommand
{
    /** @var list<string> */
    public array $stderr = [];

    protected function writeErrLn(string $line): void
    {
        $this->stderr[] = $line;
    }
}
