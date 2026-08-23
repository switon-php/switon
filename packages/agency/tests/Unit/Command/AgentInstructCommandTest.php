<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Command\AgentCommand;
use Switon\Agency\Instruct\Response;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Ai\Charter;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message;
use Switon\Ai\Thread;
use Switon\Core\ConsoleInterface;
use Switon\Core\Json;
use Switon\Testing\Mock\MockConsole;

use function array_filter;
use function array_values;
use function str_starts_with;

#[CoversClass(AgentCommand::class)]
class AgentInstructCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->container->set(AgentCommand::class, StderrCapturingInstructCommand::class);
    }

    public function testInstructPrintsUnwrappedData(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = $this->closeWith(['answer' => 'yes']);
        $this->container->set(LlmInterface::class, $stub);
        $command = $this->command();

        $code = $command->instructAction(
            'file-researcher',
            'reply',
            '{"answer":{"type":"string"}}',
        );

        $this->assertSame(ConsoleInterface::SUCCESS, $code);
        $this->assertSame(
            [Json::stringify(['answer' => 'yes'], JSON_PRETTY_PRINT)],
            $this->stdout(),
        );
        $this->assertSame([], $command->stderr);
    }

    public function testInstructLimitsTheToolGrant(): void
    {
        $seen = [];
        $stub = new StubLlm();
        $stub->responses[] = function (Thread $thread, Message $input, Charter $charter) use (&$seen): \Switon\Ai\Completion {
            $seen = array_values(array_filter(
                array_keys($charter->tools),
                static fn (string $name): bool => !str_starts_with($name, 'tmp_instruct_'),
            ));

            return $this->closeWith(['answer' => 'yes'])($thread, $input, $charter);
        };
        $this->container->set(LlmInterface::class, $stub);
        $command = $this->command();

        $code = $command->instructAction('file-researcher', 'reply', '{"answer":{"type":"string"}}', 'read');

        $this->assertSame(ConsoleInterface::SUCCESS, $code);
        $this->assertContains('read', $seen);
        $this->assertNotContains('grep', $seen);
        $this->assertNotContains('glob', $seen);
        $this->assertNotContains('ls', $seen);
    }

    public function testInstructFailsWhenTheModelDoesNotClose(): void
    {
        $this->container->set(LlmInterface::class, new StubLlm());
        $command = $this->command();

        $code = $command->instructAction('file-researcher', 'reply', '{"answer":{"type":"string"}}');

        $this->assertSame(ConsoleInterface::FAILURE, $code);
        $this->assertSame([], $this->stdout());
        $this->assertNotSame([], $command->stderr);
    }

    public function testInstructRejectsASchemaArray(): void
    {
        $command = $this->command();

        $code = $command->instructAction('file-researcher', 'reply', '["answer"]');

        $this->assertSame(ConsoleInterface::FAILURE, $code);
        $this->assertSame([], $this->stdout());
        $this->assertSame(['--schema must be a JSON object of field definitions.'], $command->stderr);
    }

    public function testInstructRequiresMessage(): void
    {
        $command = $this->command();

        $code = $command->instructAction('file-researcher', '  ');

        $this->assertSame(ConsoleInterface::FAILURE, $code);
        $this->assertSame([], $this->stdout());
        $this->assertSame(['--message is required.'], $command->stderr);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return callable(Thread, Message, Charter): \Switon\Ai\Completion
     */
    protected function closeWith(array $data): callable
    {
        return static function (Thread $thread, Message $input, Charter $charter) use ($data): \Switon\Ai\Completion {
            unset($thread, $input);
            $wire = '';
            foreach (array_keys($charter->tools) as $name) {
                if (str_starts_with($name, 'tmp_instruct_')) {
                    $wire = $name;
                    break;
                }
            }

            return StubLlm::assistantWithToolCall($wire, [
                'code' => Response::OK,
                'data' => $data,
            ]);
        };
    }

    protected function command(): StderrCapturingInstructCommand
    {
        return $this->container->get(AgentCommand::class);
    }

    /** @return list<string> */
    protected function stdout(): array
    {
        return $this->container->get(MockConsole::class)->getOutput();
    }
}

class StderrCapturingInstructCommand extends AgentCommand
{
    /** @var list<string> */
    public array $stderr = [];

    protected function writeErrLn(string $line): void
    {
        $this->stderr[] = $line;
    }
}
