<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Agency\Agent;
use Switon\Ai\Event\TurnSuspended;
use Switon\Agency\Loop;
use Switon\Agency\ExtensionInterface;
use Switon\Agency\Tests\Fixtures\AlwaysReviewTool;
use Switon\Agency\Tests\Fixtures\MutableExtensionRegistry;
use Switon\Agency\Tests\Fixtures\StubTool;
use Switon\Agency\Tests\TestCase;
use Switon\Agency\Tests\Unit\Fixtures\StubLlm;
use Switon\Agency\Tool\FormAskTool;
use Switon\Ai\AgentInterface;
use Switon\Ai\Signal\AssistantAbortSignal;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Ai\ToolCall;
use Switon\Ai\Exception\ToolAbortedException;
use Switon\Ai\Suspension\RetryContinuation;
use Switon\Ai\Suspension\ToolReviewContinuation;
use Switon\Ai\Suspension\ToolReplyContinuation;
use Switon\Ai\Suspension\RetryInterrupt;
use Switon\Ai\LlmInterface;
use Switon\Ai\Suspension\ToolReviewInterrupt;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Suspension\ToolReplyInterrupt;
use Switon\Ai\Exception\ToolDeniedException;
use Switon\Tooling\ToolsInterface;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Ai\Message\UserMessage;
use Switon\Core\Json;
use Switon\Core\PathAliasInterface;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Thread\Threads;
use Switon\Tooling\Tools;
use RuntimeException;
use Switon\Ai\Thread;

/**
 * End-to-end suspend → continue → settle for supported {@see \Switon\Ai\Suspension\Interrupt} kinds.
 *
 * Asserts in-memory turn, {@see TurnSuspended}, disk {@see Threads::TURN_FILE} /
 * {@see ThreadsInterface::load()} persisted conversation
 * after commit. Covers: fail before tools · fail after tool result · review · frontend reply ·
 * open reconnect.
 */
#[CoversClass(Loop::class)]
#[CoversClass(Agent::class)]
#[Group('coroutine')]
class TurnSuspendE2eTest extends TestCase
{
    public function testE2eLlmFailBeforeToolsThenRetryCommits(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = static function (): never {
            throw new RuntimeException('llm down before tools');
        };
        $stub->responses[] = StubLlm::assistant('recovered-before-tools');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(),
        ]);
        $error = $this->tryRun($agent, UserMessage::of('hello'));
        $this->assertNotNull($error);

        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Suspended, $turn->status);
        $this->assertInstanceOf(RetryInterrupt::class, $turn->interrupt);
        $this->assertSame(RetryInterrupt::REASON_LLM_FAILED, $turn->interrupt->reason);
        $this->assertTurnSuspended(RetryInterrupt::class);
        $this->assertSuspendedOnDisk($agent, RetryInterrupt::class);

        $this->runForTest($agent, RetryContinuation::of());
        $this->assertSame('recovered-before-tools', $this->lastAssistantContent($agent));
        $this->assertCommittedOnDisk($agent, 'recovered-before-tools');
    }

    public function testE2eToolResultThenLlmFailThenRetryCommits(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo', ['text' => 'hi'], 'call_echo_1');
        $stub->responses[] = static function (): never {
            throw new RuntimeException('llm down after tool result');
        };
        $stub->responses[] = StubLlm::assistant('recovered-after-tool');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'pong'),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]));

        $agent = $this->make(Agent::class, [
            'profile' => $this->profileWithTools($this->testProfile(), 'echo'),
        ]);
        $error = $this->tryRun($agent, UserMessage::of('echo then die'));
        $this->assertNotNull($error);

        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertInstanceOf(RetryInterrupt::class, $turn->interrupt);
        $this->assertTrue($this->turnHasToolResult($turn, 'call_echo_1', 'pong'));
        $this->assertTurnSuspended(RetryInterrupt::class);
        $disk = $this->assertSuspendedOnDisk($agent, RetryInterrupt::class);
        $this->assertTrue($this->turnHasToolResult($disk, 'call_echo_1', 'pong'));

        $this->runForTest($agent, RetryContinuation::of());
        $this->assertSame('recovered-after-tool', $this->lastAssistantContent($agent));
        $committed = $this->assertCommittedOnDisk($agent, 'recovered-after-tool');
        $this->assertTrue($this->turnHasToolResult($committed, 'call_echo_1', 'pong'));
    }

    public function testE2eLlmFailThenRejectSettlesWithoutDrive(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = static function (): never {
            throw new RuntimeException('llm down reject path');
        };
        $stub->responses[] = StubLlm::assistant('should-not-run');
        $this->container->set(LlmInterface::class, $stub);

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(),
        ]);
        $error = $this->tryRun($agent, UserMessage::of('hello'));
        $this->assertNotNull($error);
        $this->assertInstanceOf(RetryInterrupt::class, $agent->thread()->lastTurn()?->interrupt);

        $this->runForTest($agent, RetryContinuation::of(RetryContinuation::DECISION_REJECT));
        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Aborted, $turn->status);
        $this->assertNull($turn->interrupt);
        $this->assertGreaterThan(0.0, $turn->closedAt);
        $this->assertSame(1, $stub->streamCalls);

        $datadir = $agent->thread()->datadir;
        $this->assertFileDoesNotExist($datadir . '/' . Threads::TURN_FILE);
        $tipThread = Thread::of($agent->threadId(), $datadir);
        $this->threadStore()->load($tipThread);
        $tip = $tipThread->lastTurn();
        $this->assertNotNull($tip);
        $this->assertSame(TurnStatus::Aborted, $tip->status);
        $this->assertGreaterThan(0.0, $tip->closedAt);
        $output = $tip->lastCompletion()->output;
        $this->assertInstanceOf(AssistantAbortSignal::class, $output);
        $this->assertSame(AssistantAbortSignal::KIND_EXCEPTION, $output->kind);
        $this->assertStringContainsString('llm down reject path', $output->body);
    }

    public function testE2eReviewDenyViaLoopThenDenyMailCommits(): void
    {
        $review = new AlwaysReviewTool();
        $this->extensionRegistry->set($review);

        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo', ['text' => 'secret'], 'call_review_1');
        $stub->responses[] = StubLlm::assistant('after-deny');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'should-not-run'),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]));

        $agent = $this->make(Agent::class, [
            'profile' => $this->profileWithTools($this->testProfile(), 'echo'),
        ]);
        $this->runForTest($agent, UserMessage::of('need review'));

        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertInstanceOf(ToolReviewInterrupt::class, $turn->interrupt);
        $this->assertSame('call_review_1', $turn->interrupt->callId);
        $this->assertSame(['call_review_1'], $review->seen);
        $this->assertTurnSuspended(ToolReviewInterrupt::class);
        $this->assertSuspendedOnDisk($agent, ToolReviewInterrupt::class);

        $this->runForTest($agent, ToolReviewContinuation::deny('call_review_1', 'blocked'));
        $this->assertSame('after-deny', $this->lastAssistantContent($agent));
        $committed = $this->assertCommittedOnDisk($agent, 'after-deny');
        $this->assertTrue($this->turnHasToolResult($committed, 'call_review_1', 'blocked', ToolDeniedException::class));
    }

    public function testE2eTwoReviewDeniesShareOneToolCompletion(): void
    {
        $review = new AlwaysReviewTool();
        $this->extensionRegistry->set($review);

        $batch = Completion::of(UserMessage::of(''), new AssistantMessage('', [
            new ToolCall('call_a', 'echo', ['text' => 'a']),
            new ToolCall('call_b', 'echo', ['text' => 'b']),
        ]));
        $batch->finishReason = 'tool_calls';
        $stub = new StubLlm();
        $stub->responses[] = $batch;
        $stub->responses[] = StubLlm::assistant('after-both');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'should-not-run'),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]));

        $agent = $this->make(Agent::class, [
            'profile' => $this->profileWithTools($this->testProfile(), 'echo'),
        ]);
        $this->runForTest($agent, UserMessage::of('need review'));

        $turn = $agent->thread()->lastTurn();
        $this->assertInstanceOf(ToolReviewInterrupt::class, $turn->interrupt);
        $this->assertSame('call_a', $turn->interrupt->callId);
        $this->assertCount(1, $this->toolRows($turn));
        $this->assertSame([], $this->toolRows($turn)[0]->input->results);

        $this->runForTest($agent, ToolReviewContinuation::deny('call_a', 'no-a'));
        $turn = $agent->thread()->lastTurn();
        $this->assertInstanceOf(ToolReviewInterrupt::class, $turn->interrupt);
        $this->assertSame('call_b', $turn->interrupt->callId);
        $rows = $this->toolRows($turn);
        $this->assertCount(1, $rows);
        $this->assertSame('call_a', $rows[0]->input->results[0]->id);

        $this->runForTest($agent, ToolReviewContinuation::deny('call_b', 'no-b'));
        $turn = $agent->thread()->lastTurn();
        $this->assertSame('after-both', $this->lastAssistantContent($agent));
        $rows = $this->toolRows($turn);
        $this->assertCount(1, $rows);
        $this->assertSame(['call_a', 'call_b'], array_map(
            static fn ($result) => $result->id,
            $rows[0]->input->results,
        ));
        $this->assertSame(['call_a', 'call_b'], $review->seen);
    }

    public function testNewUserMessageClosesOpenToolBeforeAbortingSuspendedTurn(): void
    {
        $review = new AlwaysReviewTool();
        $this->extensionRegistry->set($review);

        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall('echo', ['text' => 'secret'], 'call_review_1');
        $stub->responses[] = StubLlm::assistant('fresh');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'should-not-run'),
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]));

        $agent = $this->make(Agent::class, [
            'profile' => $this->profileWithTools($this->testProfile(), 'echo'),
        ]);
        $this->runForTest($agent, UserMessage::of('need review'));

        $suspendedId = $agent->thread()->lastTurn()?->turnId;
        $this->assertNotNull($suspendedId);

        $this->runForTest($agent, UserMessage::of('new'));

        $turns = $agent->thread()->turns;
        $closed = $turns[0];
        $this->assertSame($suspendedId, $closed->turnId);
        $this->assertSame(TurnStatus::Aborted, $closed->status);
        $this->assertNull($closed->interrupt);
        $this->assertTrue($this->turnHasToolResult(
            $closed,
            'call_review_1',
            'This call was not completed.',
            ToolAbortedException::class,
        ));
        $signal = $closed->lastCompletion()->output;
        $this->assertInstanceOf(AssistantAbortSignal::class, $signal);
        $this->assertSame(AssistantAbortSignal::KIND_SUPERSEDED, $signal->kind);
        $this->assertSame('fresh', $this->lastAssistantContent($agent));
        $this->assertSame(TurnStatus::Completed, $turns[array_key_last($turns)]->status);
    }

    public function testE2eReviewAllowViaLoopThenFormAskThenFrontendReplyCommits(): void
    {
        $review = new AlwaysReviewTool();
        $this->extensionRegistry->set($review);

        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall(
            'form_ask',
            [
                'id' => 'e2e-pick',
                'fields' => [[
                    'name' => 'choice',
                    'label' => 'Pick',
                    'type' => 'radio',
                    'options' => ['a', 'b'],
                ]],
            ],
            'call_form_review',
        );
        $stub->responses[] = StubLlm::assistant('got-frontend-reply');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(FormAskTool::class, [
            'class' => FormAskTool::class,
            'types' => ['radio'],
        ]);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'form_ask' => FormAskTool::class,
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]));

        $agent = $this->make(Agent::class, [
            'profile' => $this->profileWithTools($this->testProfile(), 'form_ask'),
        ]);
        $this->runForTest($agent, UserMessage::of('ask with review'));

        $this->assertInstanceOf(ToolReviewInterrupt::class, $agent->thread()->lastTurn()?->interrupt);
        $this->assertSuspendedOnDisk($agent, ToolReviewInterrupt::class);

        $this->runForTest($agent, ToolReviewContinuation::allow('call_form_review'));
        $turn = $agent->thread()->lastTurn();
        $this->assertNotNull($turn);
        $this->assertInstanceOf(ToolReplyInterrupt::class, $turn->interrupt);
        $this->assertSame('e2e-pick', $turn->interrupt->arguments['id'] ?? null);
        $this->assertTurnSuspended(ToolReplyInterrupt::class);
        $this->assertSuspendedOnDisk($agent, ToolReplyInterrupt::class);

        $this->runForTest($agent, ToolReplyContinuation::of('call_form_review', [
            'outcome' => 'answered',
            'id' => 'e2e-pick',
            'values' => ['choice' => 'a'],
        ]));
        $this->assertSame('got-frontend-reply', $this->lastAssistantContent($agent));
        $this->assertCommittedOnDisk($agent, 'got-frontend-reply');
    }

    public function testE2eFormAskFrontendReplyWithoutReviewCommits(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall(
            'form_ask',
            [
                'id' => 'direct-pick',
                'fields' => [[
                    'name' => 'choice',
                    'label' => 'Pick',
                    'type' => 'radio',
                    'options' => ['a', 'b'],
                ]],
            ],
            'call_form_direct',
        );
        $stub->responses[] = StubLlm::assistant('direct-ok');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(FormAskTool::class, [
            'class' => FormAskTool::class,
            'types' => ['radio'],
        ]);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'form_ask' => FormAskTool::class,
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]));

        $agent = $this->make(Agent::class, [
            'profile' => $this->profileWithTools($this->testProfile(), 'form_ask'),
        ]);
        $this->runForTest($agent, UserMessage::of('ask form'));

        $this->assertInstanceOf(ToolReplyInterrupt::class, $agent->thread()->lastTurn()?->interrupt);
        $this->assertTurnSuspended(ToolReplyInterrupt::class);
        $this->assertSuspendedOnDisk($agent, ToolReplyInterrupt::class);

        $this->runForTest($agent, ToolReplyContinuation::of('call_form_direct', [
            'outcome' => 'answered',
            'id' => 'direct-pick',
            'values' => ['choice' => 'b'],
        ]));
        $this->assertSame('direct-ok', $this->lastAssistantContent($agent));
        $this->assertCommittedOnDisk($agent, 'direct-ok');
    }

    public function testE2eFormAskSuspendSurvivesProcessResumeThenReply(): void
    {
        $stub = new StubLlm();
        $stub->responses[] = StubLlm::assistantWithToolCall(
            'form_ask',
            [
                'id' => 'resume-pick',
                'fields' => [[
                    'name' => 'choice',
                    'label' => 'Pick',
                    'type' => 'radio',
                    'options' => ['a', 'b'],
                ]],
            ],
            'call_form_resume',
        );
        $stub->responses[] = StubLlm::assistant('after-process-resume');
        $this->container->set(LlmInterface::class, $stub);
        $this->container->replace(FormAskTool::class, [
            'class' => FormAskTool::class,
            'types' => ['radio'],
        ]);
        $this->container->replace(ToolsInterface::class, $this->make(Tools::class, [
            'tools' => [
                'form_ask' => FormAskTool::class,
            ],
            'toolkits' => $this->builtinToolkitConfig(),
        ]));

        $agent = $this->make(Agent::class, [
            'profile' => $this->profileWithTools($this->testProfile(), 'form_ask'),
        ]);
        $this->runForTest($agent, UserMessage::of('suspend then reconnect'));
        $datadir = $agent->thread()->datadir;
        $this->assertSuspendedOnDisk($agent, ToolReplyInterrupt::class);

        $revived = $this->startRoot($datadir);
        $open = $revived->thread()->lastTurn();
        $this->assertNotNull($open);
        $this->assertSame(TurnStatus::Suspended, $open->status);
        $this->assertInstanceOf(ToolReplyInterrupt::class, $open->interrupt);
        $this->assertSame('call_form_resume', $open->interrupt->callId);

        $this->runForTest($revived, ToolReplyContinuation::of('call_form_resume', [
            'outcome' => 'answered',
            'id' => 'resume-pick',
            'values' => ['choice' => 'a'],
        ]));
        $this->assertSame('after-process-resume', $this->lastAssistantContent($revived));
        $this->assertCommittedOnDisk($revived, 'after-process-resume');
    }

    /** @param class-string $interruptClass */
    protected function assertTurnSuspended(string $interruptClass): void
    {
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);
        $events = $dispatcher->getEventsByClass(TurnSuspended::class);
        $this->assertNotEmpty($events);
        $this->assertInstanceOf($interruptClass, $events[array_key_last($events)]->interrupt);
    }

    /**
     * Reload from datadir: open turn file + load last turn keep interrupt.
     *
     * @param class-string $interruptClass
     */
    protected function assertSuspendedOnDisk(AgentInterface $agent, string $interruptClass): Turn
    {
        $datadir = $agent->thread()->datadir;
        $this->assertNotSame('', $datadir);
        $resolved = $this->container->get(PathAliasInterface::class)->resolve($datadir);
        $this->assertFileExists($resolved . '/' . Threads::TURN_FILE);

        $loaded = Thread::of($agent->threadId(), $datadir);
        $this->threadStore()->load($loaded);
        $open = $loaded->lastTurn();
        $this->assertNotNull($open);
        $this->assertSame(TurnStatus::Suspended, $open->status);
        $this->assertInstanceOf($interruptClass, $open->interrupt);

        $revived = Turn::decode(Json::parse(Json::stringify($open)));
        $this->assertInstanceOf($interruptClass, $revived->interrupt);

        return $open;
    }

    /** Committed turn on disk: no open file; {@see ThreadsInterface::load()} matches memory. */
    /** @return list<Completion> */
    protected function toolRows(Turn $turn): array
    {
        $rows = [];
        foreach ($turn->completions as $completion) {
            if ($completion->input instanceof ToolMessage) {
                $rows[] = $completion;
            }
        }

        return $rows;
    }

    protected function assertCommittedOnDisk(AgentInterface $agent, string $assistantNeedle): Turn
    {
        $datadir = $agent->thread()->datadir;
        $resolved = $this->container->get(PathAliasInterface::class)->resolve($datadir);
        $this->assertFileDoesNotExist($resolved . '/' . Threads::TURN_FILE);

        $loaded = Thread::of($agent->threadId(), $datadir);
        $this->threadStore()->load($loaded);
        $turn = $loaded->lastTurn();
        $this->assertNotNull($turn);
        $this->assertSame(TurnStatus::Completed, $turn->status);
        $this->assertNull($turn->interrupt);

        $found = false;
        foreach ($this->persistedAgentMessages($agent) as $message) {
            if ($message instanceof \Switon\Ai\Message\AssistantMessage
                && str_contains((string) ($message->content ?? ''), $assistantNeedle)
            ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'persisted conversation missing assistant text: ' . $assistantNeedle);

        return $turn;
    }

    protected function turnHasToolResult(
        Turn $turn,
        string $callId,
        string $needle,
        ?string $failure = null,
    ): bool {
        foreach ($turn->completions as $completion) {
            $input = $completion->input;
            if (!$input instanceof ToolMessage) {
                continue;
            }
            foreach ($input->results as $result) {
                if ($result->id !== $callId) {
                    continue;
                }
                if ($failure !== null && !$this->failureClassIs($result, $failure)) {
                    continue;
                }

                return str_contains(Json::text($result->wire()), $needle);
            }
        }

        return false;
    }

    protected function failureClassIs(\Switon\Ai\ToolResult $result, string $class): bool
    {
        if ($result->content instanceof $class) {
            return true;
        }

        return is_array($result->content) && ($result->content['class'] ?? null) === $class;
    }
}
