<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Ai\AbstractExtension;
use Switon\Ai\ExtensionInterface;
use Switon\Agency\Tests\Fixtures\MutableExtensionRegistry;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Agent;
use Switon\Ai\AgentInterface;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Part\TextPart;
use Switon\Ai\Envelope;
use Switon\Ai\LlmInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\ToolResult;
use Switon\Ai\Suspension\ToolReviewInterrupt;
use Switon\Ai\Suspension\ToolReviewContinuation;
use Switon\Agency\Tests\Fixtures\AlwaysReviewTool;
use Switon\Ai\Turn;
use Switon\Ai\Profile;
use Switon\Tooling\ToolsInterface;
use Switon\Agency\Tests\Fixtures\StubTool;
use Switon\Tooling\Tools;
use Switon\Agency\Thread\TaskFinishSignal;
use Switon\Ai\Suspension\RetryContinuation;
use Switon\Ai\Message\UserMessage;
use RuntimeException;

#[CoversClass(Extensions::class)]
#[CoversClass(AbstractExtension::class)]
#[Group('coroutine')]
class ExtensionsTest extends TestCase
{
    private function useEchoTool(): void
    {
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'pong'),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]));
    }

    private function echoProfile(): Profile
    {
        return $this->profileWithTools($this->testProfile(prompt: 'Hi.'), 'echo');
    }

    public function testEveryPointFiresInOrder(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo', ['text' => 'hi'], 'call_x');
        $stub->responses[] = StubLlm::assistant('done');
        $this->container->set(LlmInterface::class, $stub);
        $this->registry()->set(new RecordingExtension());
        $this->useEchoTool();

        $agent = $this->make(Agent::class, [
            'profile' => $this->echoProfile(),
        ]);
        $this->runForTest($agent, UserMessage::of('go'));

        $extension = $this->registry()->all()[0];
        $this->assertInstanceOf(RecordingExtension::class, $extension);
        $this->assertSame(['inbound', 'beforeTurn'], array_slice($extension->calls, 0, 2));
        $this->assertContains('beforeModel', $extension->calls);
        $this->assertContains('afterModel', $extension->calls);
        $this->assertSame(['beforeTool', 'afterTool'], array_values(array_intersect(
            $extension->calls,
            ['beforeTool', 'afterTool'],
        )));
        $this->assertSame('afterTurn', $extension->calls[count($extension->calls) - 1]);
    }

    public function testInboundRunsBeforeTheTurnExists(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('ok');
        $this->container->set(LlmInterface::class, $stub);
        $extension = new RecordingExtension();
        $this->registry()->set($extension);

        $agent = $this->make(Agent::class, ['profile' => $this->testProfile(prompt: 'Hi.')]);
        $this->runForTest($agent, UserMessage::of('go'));

        // `inbound` sees no turn; `beforeTurn` runs before the turn joins the thread.
        $this->assertSame([0, 0], $extension->turnCounts);
    }

    public function testTaskFinishBeforeTurnRunsBeforeTheTurnJoins(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('ok');
        $stub->responses[] = StubLlm::assistant('noted');
        $this->container->set(LlmInterface::class, $stub);
        $extension = new RecordingExtension();
        $this->registry()->set($extension);

        $agent = $this->make(Agent::class, ['profile' => $this->testProfile(prompt: 'Hi.')]);
        $this->runForTest($agent, UserMessage::of('go'));
        $extension->calls = [];
        $extension->turnCounts = [];

        $this->runForTest(
            $agent,
            new TaskFinishSignal(['task_id' => 't1', 'thread_id' => 'host.t1', 'status' => 'completed'], 'done'),
        );

        $this->assertSame(['inbound', 'beforeTurn'], array_slice($extension->calls, 0, 2));
        $this->assertSame([1, 1], array_slice($extension->turnCounts, 0, 2));
        $this->assertSame($this->modelForTest($agent), $extension->modelNameAtBeforeTurn);
    }

    public function testRetryRejectFiresAfterTurn(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = static function (): never {
            throw new RuntimeException('llm down');
        };
        $this->container->set(LlmInterface::class, $stub);
        $extension = new RecordingExtension();
        $this->registry()->set($extension);

        $agent = $this->make(Agent::class, ['profile' => $this->testProfile(prompt: 'Hi.')]);
        $this->tryRun($agent, UserMessage::of('go'));
        $extension->calls = [];

        $this->runForTest($agent, RetryContinuation::of(RetryContinuation::DECISION_REJECT));

        $this->assertSame(['inbound', 'afterTurn'], $extension->calls);
    }

    public function testBeforeTurnCanStampAttributesAndAfterTurnSeesThem(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('ok');
        $this->container->set(LlmInterface::class, $stub);
        $extension = new RecordingExtension(stampAttributes: true);
        $this->registry()->set($extension);

        $agent = $this->make(Agent::class, ['profile' => $this->testProfile(prompt: 'Hi.')]);
        $this->runForTest($agent, UserMessage::of('go'));

        $this->assertSame(['trace_id' => 'abc'], $agent->thread()->lastTurn()->attributes);
        $this->assertSame('abc', $extension->seenAttributeInAfterTurn);
    }

    public function testAfterToolCanReshapeTheResult(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo', ['text' => 'secret'], 'call_x');
        $stub->responses[] = StubLlm::assistant('done');
        $this->container->set(LlmInterface::class, $stub);
        $this->registry()->set(new RedactingExtension());
        $this->useEchoTool();

        $agent = $this->make(Agent::class, ['profile' => $this->echoProfile()]);
        $this->runForTest($agent, UserMessage::of('go'));

        $result = null;
        foreach ($agent->thread()->lastTurn()->completions as $completion) {
            if ($completion->input instanceof ToolMessage) {
                $result = $completion->input->results[0] ?? null;
            }
        }
        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertSame('[redacted]', $result->content);
    }

    public function testReviewToolSuspendsAndDoesNotAskAgainAfterApproval(): void
    {
        $review = new AlwaysReviewTool();
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo', ['text' => 'x'], 'call_x');
        $stub->responses[] = StubLlm::assistant('done');
        $this->container->set(LlmInterface::class, $stub);
        $this->registry()->set($review);
        $this->useEchoTool();

        $agent = $this->make(Agent::class, ['profile' => $this->echoProfile()]);
        $this->runForTest($agent, UserMessage::of('go'));

        $turn = $agent->thread()->lastTurn();
        $this->assertInstanceOf(ToolReviewInterrupt::class, $turn->interrupt);
        $this->assertSame(['call_x'], $review->seen);

        // Approving the call must not re-ask: review is skipped for that callId.
        $this->runForTest($agent, ToolReviewContinuation::allow('call_x'));

        $this->assertNull($agent->thread()->lastTurn()->interrupt);
        $this->assertSame(['call_x'], $review->seen);
    }

    public function testInboundCanAttachContextParts(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistant('ok');
        $this->container->set(LlmInterface::class, $stub);
        $this->registry()->set(new ContextExtension());

        $agent = $this->make(Agent::class, ['profile' => $this->testProfile(prompt: 'Hi.')]);
        $this->runForTest($agent, UserMessage::of('question'));

        $message = $agent->thread()->lastTurn()->completions[0]->input;
        $this->assertInstanceOf(UserMessage::class, $message);
        $this->assertSame('question', $message->text());
        $this->assertInstanceOf(TextPart::class, $message->parts[1]);
        $this->assertSame('extra context', $message->parts[1]->text);
    }

    private function registry(): MutableExtensionRegistry
    {
        return $this->extensionRegistry;
    }
}

/** Records every lifecycle call plus the turn count visible at that moment. */
class RecordingExtension extends AbstractExtension
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<int> */
    public array $turnCounts = [];

    public ?string $seenAttributeInAfterTurn = null;

    public ?string $modelNameAtBeforeTurn = null;

    public function __construct(private bool $stampAttributes = false)
    {
    }

    public function inbound(AgentInterface $agent, Envelope $envelope): void
    {
        $this->calls[] = 'inbound';
        $this->turnCounts[] = count($agent->thread()->turns);
    }

    public function beforeTurn(AgentInterface $agent, Turn $turn): void
    {
        $this->calls[] = 'beforeTurn';
        $this->turnCounts[] = count($agent->thread()->turns);
        $this->modelNameAtBeforeTurn = $turn->model;
        if ($this->stampAttributes) {
            $turn->attributes['trace_id'] = 'abc';
        }
    }

    public function afterTurn(AgentInterface $agent, Turn $turn): void
    {
        $this->calls[] = 'afterTurn';
        $this->seenAttributeInAfterTurn = $turn->attributes['trace_id'] ?? null;
    }

    public function beforeModel(AgentInterface $agent, Charter $charter): void
    {
        $this->calls[] = 'beforeModel';
    }

    public function afterModel(AgentInterface $agent, Completion $completion): void
    {
        $this->calls[] = 'afterModel';
    }

    public function beforeTool(AgentInterface $agent, ToolCall $call): void
    {
        $this->calls[] = 'beforeTool';
    }

    public function afterTool(AgentInterface $agent, ToolResult $result): void
    {
        $this->calls[] = 'afterTool';
    }
}

/** Rewrites any tool output to a placeholder. */
class RedactingExtension extends AbstractExtension
{
    public function afterTool(AgentInterface $agent, ToolResult $result): void
    {
        $result->content = '[redacted]';
    }
}

/** Throws from `beforeTool` — proves side-effect hooks can abort a batch. */
class ThrowingSideEffectExtension extends AbstractExtension
{
    public function beforeTool(AgentInterface $agent, ToolCall $call): void
    {
        throw new \RuntimeException('hook boom');
    }
}

/** Widens every user message with model-only context. */
class ContextExtension extends AbstractExtension
{
    public function inbound(AgentInterface $agent, Envelope $envelope): void
    {
        $letter = $envelope->letter;
        if (!$letter instanceof UserMessage) {
            return;
        }
        $envelope->letter = new UserMessage([...$letter->parts, TextPart::of('extra context')]);
    }
}
