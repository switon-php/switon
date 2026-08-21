<?php

declare(strict_types=1);

namespace Switon\Langfuse\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Switon\Ai\AgentInterface;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Event\TurnSuspended;
use Switon\Ai\LlmTiming;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\Part\PinnedPart;
use Switon\Ai\Part\RefusalPart;
use Switon\Ai\Part\TextPart;
use Switon\Ai\Suspension\RetryInterrupt;
use Switon\Ai\Suspension\ToolReplyInterrupt;
use Switon\Ai\Thread;
use Switon\Ai\TokenUsage;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Ai\ToolSchema;
use Switon\Ai\ToolTiming;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Langfuse\ClientInterface;
use Switon\Langfuse\Observability\LlmListener;
use Switon\Langfuse\Otlp;
use Switon\Langfuse\Tests\Fixtures\RecordingClient;
use Switon\Langfuse\Tests\Fixtures\StubLlm;
use Switon\Langfuse\Tests\TestCase;
use Switon\Llm\Event\CompletionFailed;
use Switon\Llm\Event\CompletionResponded;
use Switon\Tooling\Event\ToolFinished;
use Switon\Tooling\ToolsInterface;

#[CoversClass(LlmListener::class)]
class LlmListenerTest extends TestCase
{
    protected RecordingClient $recording;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recording = new RecordingClient();
        $this->container->set(ClientInterface::class, $this->recording);
    }

    public function testCompletionLifecycleCreatesGenerationSpan(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-1');
        $llm = new StubLlm();
        $completion = Completion::of(UserMessage::of('Hello'));
        $thread->turns[] = Turn::of($completion->completeId, status: TurnStatus::Running, model: 'gpt-4o-mini');
        $completion->output = new AssistantMessage('Hi there');
        $completion->usage = new TokenUsage(input: 5, output: 3);
        $listener->onCompletionResponded(new CompletionResponded($llm, $thread, $completion, Charter::of()));

        $this->assertCount(1, $this->recording->ingests);
        $span = $this->recording->observation();

        $this->assertSame(Otlp::traceId($completion->completeId), $span->traceId);
        $this->assertSame('answer', $span->name);
        $this->assertSame('conv-1', $span->attributes['langfuse.trace.name']);
        $this->assertSame('conv-1', $span->attributes['langfuse.session.id']);
        $this->assertSame('generation', $span->attributes['langfuse.observation.type']);
        $this->assertSame('stub', $span->attributes['langfuse.observation.model.name']);
        $this->assertSame('Hi there', json_decode($span->attributes['langfuse.observation.output'], true));
        $this->assertSame(
            [['role' => 'user', 'content' => 'Hello']],
            json_decode($span->attributes['langfuse.observation.input'], true),
        );
        $this->assertSame(
            ['input' => 5, 'output' => 3, 'total' => 8],
            json_decode($span->attributes['langfuse.observation.usage_details'], true),
        );
        $this->assertFalse($span->isError);
    }

    public function testGenerationRecordsExclusiveUsageAndTiming(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-usage');
        $completion = Completion::of(UserMessage::of('Hello'));
        $completion->completeId = 'usage-complete';
        $completion->step = 2;
        $completion->timing = LlmTiming::of(wall: 1.5, ttft: 0.2, reasoning: 0.4, start: 1_700_000_000.0);
        $thread->turns[] = Turn::of('turn-1', [$completion], TurnStatus::Running, 'claude-sonnet');
        $completion->output = new AssistantMessage('Hi');
        $completion->finishReason = 'end_turn';
        $completion->usage = new TokenUsage(input: 3545, output: 124, cacheRead: 3085, cacheWrite: 0, reasoning: 40);

        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $completion, Charter::of(), stream: true));

        $span = $this->recording->observation();
        $attributes = $span->attributes;

        $this->assertSame('turn-1', $attributes['langfuse.trace.metadata.turn_id']);

        $this->assertSame(Otlp::traceId('turn-1'), $span->traceId);
        $this->assertSame(1_700_000_000.0, $span->startUnix);
        $this->assertSame(1_700_000_001.5, $span->endUnix);
        $this->assertSame(Otlp::isoFromUnix(1_700_000_000.2), $attributes['langfuse.observation.completion_start_time']);
        $this->assertSame('true', $attributes['langfuse.observation.metadata.stream']);
        $this->assertSame('2', $attributes['langfuse.observation.metadata.step']);
        $this->assertSame('claude-sonnet', $attributes['langfuse.observation.metadata.catalog_model']);
        $this->assertSame('end_turn', $attributes['langfuse.observation.metadata.finish_reason']);
        $this->assertEquals(
            ['wall' => 1.5, 'ttft' => 0.2, 'reasoning' => 0.4, 'start' => 1_700_000_000.0],
            json_decode((string) $attributes['langfuse.observation.metadata.timing'], true),
        );
        $this->assertSame(
            [
                'input' => 460,
                'cache_read_input_tokens' => 3085,
                'cache_creation_input_tokens' => 0,
                'output' => 84,
                'reasoning_tokens' => 40,
                'total' => 3669,
            ],
            json_decode($attributes['langfuse.observation.usage_details'], true),
        );
    }

    public function testCompletionFailedMarksSpanAsError(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-fail');
        $completion = Completion::of(UserMessage::of('boom'));

        $listener->onCompletionFailed(new CompletionFailed(
            new StubLlm(),
            $thread,
            $completion,
            new RuntimeException('provider unavailable'),
            Charter::of(),
            httpStatus: 503,
        ));

        $span = $this->recording->observation();
        $this->assertTrue($span->isError);
        $this->assertSame('provider unavailable', $span->statusMessage);
        $this->assertSame('ERROR', $span->attributes['langfuse.observation.level']);
        $this->assertSame('provider unavailable', $span->attributes['langfuse.observation.status_message']);
        $this->assertSame('503', $span->attributes['langfuse.observation.metadata.http_status']);
        $this->assertSame(RuntimeException::class, $span->attributes['langfuse.observation.metadata.exception']);
        $this->assertSame(Otlp::traceId($completion->completeId), $span->traceId);
    }

    public function testSecondRequestInSameTurnSharesTrace(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-reuse');
        $thread->turns[] = Turn::of('1ea435b1-83df-4130-a57c-a97bd9848d96', status: TurnStatus::Running, model: 'main');

        $first = Completion::of(UserMessage::of('one'));
        $first->output = new AssistantMessage('ok');
        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $first, Charter::of()));

        $second = Completion::of(UserMessage::of('two'));
        $second->output = new AssistantMessage('done');
        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $second, Charter::of()));

        $this->assertCount(2, $this->recording->ingests);
        $traceId = '1ea435b183df4130a57ca97bd9848d96';
        $this->assertSame($traceId, $this->recording->observation(0)->traceId);
        $this->assertSame($traceId, $this->recording->observation(1)->traceId);
        $this->assertNotSame($this->recording->observation(0)->spanId, $this->recording->observation(1)->spanId);
    }

    public function testSubAgentSharesHostSessionId(): void
    {
        $listener = $this->make(LlmListener::class);
        $hostThread = 'host-uuid.root';
        $subThread = $hostThread . '.t10001';

        $completion = Completion::of(UserMessage::of('sub task'));
        $completion->output = new AssistantMessage('done');
        $listener->onCompletionResponded(new CompletionResponded(
            new StubLlm(),
            new Thread($subThread),
            $completion,
            Charter::of(),
        ));

        $span = $this->recording->observation();
        $this->assertSame($hostThread, $span->attributes['langfuse.session.id']);
        $this->assertSame($subThread, $span->attributes['langfuse.trace.name']);
        $this->assertSame($subThread, $span->attributes['langfuse.trace.metadata.thread_id']);
        $this->assertSame(Otlp::traceId($completion->completeId), $span->traceId);
    }

    public function testWireInputSkipsWorkingCompletionAndKeepsPinnedText(): void
    {
        $listener = $this->make(LlmListener::class);
        $prior = Completion::of(UserMessage::of('earlier'), new AssistantMessage('ack'));
        $current = Completion::of(UserMessage::of('now', [new PinnedPart('keep', 'pinned line')]));
        $current->output = new AssistantMessage('later');
        $thread = new Thread('conv-wire');
        $thread->turns[] = Turn::of('turn-wire', [$prior, $current], model: 'gpt-4o-mini');

        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $current, Charter::of(prompt: 'Be brief')));

        $input = json_decode((string) json_encode(json_decode(
            $this->recording->observation()->attributes['langfuse.observation.input'],
            true,
        )), true);

        $this->assertSame([
            ['role' => 'system', 'content' => 'Be brief'],
            ['role' => 'user', 'content' => 'earlier'],
            ['role' => 'assistant', 'content' => 'ack'],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'now', 'part' => 'ask'],
                ['type' => 'text', 'text' => 'pinned line', 'part' => 'pinned'],
            ]],
        ], $input);
        $this->assertSame('later', json_decode($this->recording->observation()->attributes['langfuse.observation.output'], true));
    }

    public function testThreadPartsAndToolResultsStaySeparate(): void
    {
        $listener = $this->make(LlmListener::class);
        $call = new ToolCall('c1', 'bash', ['cmd' => 'python']);
        $first = Completion::of(
            UserMessage::of('solve', [new TextPart('keep'), MediaPart::of('shot.png', 'image/png')]),
            new AssistantMessage('', [$call], reasoning: 'try 6/(1-3/4)'),
        );
        $second = Completion::of(ToolMessage::of([
            new ToolResult('24', 'c1', 'bash', parts: [new TextPart('True')]),
            new ToolResult('other', 'c2', 'read'),
        ]));
        $second->output = new AssistantMessage(null, parts: [RefusalPart::of('no')]);
        $thread = new Thread('conv-parts');
        $thread->turns[] = Turn::of('turn-parts', [$first, $second], model: 'main');

        $listener->onCompletionResponded(new CompletionResponded(
            new StubLlm(),
            $thread,
            $second,
            Charter::of(prompt: 'policy'),
        ));

        $span = $this->recording->observation();
        $input = json_decode($span->attributes['langfuse.observation.input'], true);

        $this->assertSame('user', $input[1]['role']);
        $this->assertSame([
            ['type' => 'text', 'text' => 'solve', 'part' => 'ask'],
            ['type' => 'text', 'text' => 'keep', 'part' => 'text'],
            ['type' => 'text', 'text' => 'shot.png (image/png)', 'part' => 'media'],
        ], $input[1]['content']);
        $this->assertSame([
            'id' => 'c1',
            'type' => 'function',
            'function' => ['name' => 'bash', 'arguments' => '{"cmd":"python"}'],
        ], $input[2]['tool_calls'][0]);
        $this->assertSame([['type' => 'thinking', 'content' => 'try 6/(1-3/4)']], $input[2]['thinking']);
        $this->assertSame('tool', $input[3]['role']);
        $this->assertSame('c1', $input[3]['tool_call_id']);
        $this->assertSame([
            ['type' => 'text', 'text' => '24', 'part' => 'text'],
            ['type' => 'text', 'text' => 'True', 'part' => 'text'],
        ], $input[3]['content']);
        $this->assertSame('other', $input[4]['content']);
        $this->assertSame('system', $input[0]['role']);
        $this->assertSame('no', json_decode($span->attributes['langfuse.observation.output'], true));
    }

    public function testGenerationNameFromToolCallWithCaption(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-tools');
        $completion = Completion::of(UserMessage::of('go'));
        $completion->output = new AssistantMessage('', [
            new ToolCall('c1', 'task_deliver', ['.caption' => 'Explore repo', 'name' => 'explore']),
        ]);
        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $completion, Charter::of()));

        $this->assertSame('call-tool', $this->recording->observation()->name);
    }

    public function testGenerationNameAfterToolResults(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-after-tool');
        $input = ToolMessage::of([new ToolResult('{"ok":true}', 'c1', 'read')]);
        $completion = Completion::of($input);
        $completion->output = new AssistantMessage('done');

        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $completion, Charter::of()));

        $this->assertSame('answer-with-tool-result', $this->recording->observation()->name);
    }

    public function testGenerationNameForPlainReply(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-reply');
        $completion = Completion::of(UserMessage::of('status?'));
        $completion->output = new AssistantMessage('Waiting for all three agents to report back…');

        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $completion, Charter::of()));

        $this->assertSame('answer', $this->recording->observation()->name);
    }

    public function testToolFinishCreatesToolSpanUnderGeneration(): void
    {
        $listener = $this->make(LlmListener::class);
        $call = new ToolCall('c1', 'read', ['.caption' => 'Read file', 'path' => 'a.php']);
        $completion = Completion::of(UserMessage::of('go'));
        $completion->output = new AssistantMessage('', [$call]);
        $thread = new Thread('conv-span');
        $thread->turns[] = Turn::of('turn-span', [$completion], model: 'main');

        $listener->onToolFinished(new ToolFinished(
            $this->createStub(ToolsInterface::class),
            $call,
            $this->agentFor($thread, 'conv-span'),
            new ToolResult('file body', 'c1', 'read', ToolTiming::of($call, 0.2)),
        ));

        $span = $this->recording->observation();
        $this->assertSame('tool', $span->attributes['langfuse.observation.type']);
        $this->assertSame(Otlp::traceId('turn-span'), $span->traceId);
        $this->assertSame(Otlp::spanId('c1'), $span->spanId);
        $this->assertSame(Otlp::spanId(Otlp::traceId('turn-span'), 'step-1'), $span->parentSpanId);
        $this->assertSame('read · Read file', $span->name);
        $this->assertSame(['path' => 'a.php'], json_decode($span->attributes['langfuse.observation.input'], true));
        $this->assertSame('file body', json_decode($span->attributes['langfuse.observation.output'], true));
        $this->assertEquals(
            ['receive' => 0.0, 'execute' => 0.2],
            json_decode($span->attributes['langfuse.observation.metadata.timing'], true),
        );
        $this->assertFalse($span->isError);
    }

    public function testToolErrorMarksSpan(): void
    {
        $listener = $this->make(LlmListener::class);
        $call = new ToolCall('c2', 'bash', ['cmd' => 'false']);
        $completion = Completion::of(UserMessage::of('go'));
        $completion->output = new AssistantMessage('', [$call]);
        $thread = new Thread('conv-span-err');
        $thread->turns[] = Turn::of('turn-err', [$completion], model: 'main');

        $listener->onToolFinished(new ToolFinished(
            $this->createStub(ToolsInterface::class),
            $call,
            $this->agentFor($thread, 'conv-span-err'),
            new ToolResult(new RuntimeException('disk full'), 'c2', 'bash'),
        ));

        $span = $this->recording->observation();
        $this->assertTrue($span->isError);
        $this->assertSame('RuntimeException: disk full', $span->statusMessage);
        $this->assertSame('ERROR', $span->attributes['langfuse.observation.level']);
        $this->assertSame('RuntimeException: disk full', $span->attributes['langfuse.observation.status_message']);
        $this->assertSame(
            ['class' => 'RuntimeException', 'message' => 'disk full'],
            json_decode($span->attributes['langfuse.observation.output'], true),
        );
    }

    public function testToolInterruptRecordsSuspendedSpanOnItsOwnId(): void
    {
        $listener = $this->make(LlmListener::class);
        $call = new ToolCall('c1', 'ask', ['q' => 'which']);
        $completion = Completion::of(UserMessage::of('go'));
        $completion->output = new AssistantMessage('', [$call]);
        $thread = new Thread('conv-suspend');
        $turn = Turn::of('turn-suspend', [$completion], model: 'main');
        $thread->turns[] = $turn;
        $interrupt = ToolReplyInterrupt::of($call);
        $interrupt->suspendedAt = 1_700_000_000.0;

        $listener->onTurnSuspended(new TurnSuspended($this->agentFor($thread, 'conv-suspend'), $turn, $interrupt));

        $span = $this->recording->observation();
        $this->assertSame('tool', $span->attributes['langfuse.observation.type']);
        $this->assertSame('c1', $span->attributes['langfuse.observation.metadata.tool_call_id']);
        $this->assertSame(Otlp::traceId('turn-suspend'), $span->traceId);
        $this->assertSame(Otlp::spanId('c1', 'suspend'), $span->spanId);
        $this->assertSame(Otlp::spanId(Otlp::traceId('turn-suspend'), 'step-1'), $span->parentSpanId);
        $this->assertSame(1_700_000_000.0, $span->startUnix);
        $this->assertSame('WARNING', $span->attributes['langfuse.observation.level']);
        $this->assertSame('suspended ToolReplyInterrupt', $span->attributes['langfuse.observation.status_message']);
        $this->assertSame(['q' => 'which'], json_decode($span->attributes['langfuse.observation.input'], true));
        $this->assertArrayNotHasKey('langfuse.observation.output', $span->attributes);
    }

    public function testSuspendedToolSpanDoesNotCollideWithItsFinishedSpan(): void
    {
        $listener = $this->make(LlmListener::class);
        $call = new ToolCall('c1', 'ask', []);
        $completion = Completion::of(UserMessage::of('go'));
        $completion->output = new AssistantMessage('', [$call]);
        $thread = new Thread('conv-collide');
        $turn = Turn::of('turn-collide', [$completion], model: 'main');
        $thread->turns[] = $turn;
        $agent = $this->agentFor($thread, 'conv-collide');

        $listener->onTurnSuspended(new TurnSuspended($agent, $turn, ToolReplyInterrupt::of($call)));
        $listener->onToolFinished(new ToolFinished(
            $this->createStub(ToolsInterface::class),
            $call,
            $agent,
            new ToolResult('answered', 'c1', 'ask'),
        ));

        $this->assertSame(Otlp::spanId('c1', 'suspend'), $this->recording->observation(0)->spanId);
        $this->assertSame(Otlp::spanId('c1'), $this->recording->observation(1)->spanId);
        $this->assertNotSame($this->recording->observation(0)->spanId, $this->recording->observation(1)->spanId);
    }

    public function testRetryInterruptRecordsWarningEventSpan(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-retry');
        $turn = Turn::of('turn-retry', [], model: 'main');
        $thread->turns[] = $turn;

        $listener->onTurnSuspended(new TurnSuspended(
            $this->agentFor($thread, 'conv-retry'),
            $turn,
            new RetryInterrupt(RetryInterrupt::REASON_CONTEXT_OVERFLOW, '120000 tokens'),
        ));

        // A retry suspend is not a model step, so it hangs directly under the turn root.
        $span = $this->recording->span(0, 1);
        $this->assertCount(2, $this->recording->batch(0));
        $this->assertSame('event', $span->attributes['langfuse.observation.type']);
        $this->assertSame('context_overflow', $span->name);
        $this->assertSame('120000 tokens', $span->attributes['langfuse.observation.status_message']);
        $this->assertSame('120000 tokens', $span->attributes['langfuse.observation.output']);
        $this->assertSame('WARNING', $span->attributes['langfuse.observation.level']);
    }

    public function testOfferedToolsAndSpilledBodyAreRecorded(): void
    {
        $listener = $this->make(LlmListener::class);
        $schema = new ToolSchema(
            description: 'Run a command',
            properties: ['cmd' => ['type' => 'string']],
            required: ['cmd'],
        );
        $completion = Completion::of(UserMessage::of('go'));
        $completion->output = new AssistantMessage('ok');
        $thread = new Thread('conv-tools-meta');

        $listener->onCompletionResponded(new CompletionResponded(
            new StubLlm(),
            $thread,
            $completion,
            Charter::of(tools: ['bash' => $schema], toolChoice: 'auto'),
        ));

        $attributes = $this->recording->observation()->attributes;
        $this->assertSame('auto', $attributes['langfuse.observation.metadata.tool_choice']);
        $tools = json_decode($attributes['langfuse.observation.metadata.tools'], true);
        $this->assertSame('bash', $tools[0]['name']);
        $this->assertSame('Run a command', $tools[0]['description']);
        $this->assertSame(['cmd'], $tools[0]['input_schema']['required']);

        $call = new ToolCall('c9', 'bash', ['cmd' => 'ls']);
        $completion->output = new AssistantMessage('', [$call]);
        $thread->turns[] = Turn::of('turn-recall', [$completion], model: 'main');

        $listener->onToolFinished(new ToolFinished(
            $this->createStub(ToolsInterface::class),
            $call,
            $this->agentFor($thread, 'conv-tools-meta'),
            new ToolResult('head', 'c9', 'bash', recall: '@thread/transcript-tool/turn-recall.txt'),
        ));

        $tool = $this->recording->observation(1);
        $this->assertSame('@thread/transcript-tool/turn-recall.txt', $tool->attributes['langfuse.observation.metadata.recall']);
        $this->assertSame([
            ['type' => 'text', 'text' => 'head', 'part' => 'text'],
            ['type' => 'text', 'text' => '@thread/transcript-tool/turn-recall.txt', 'part' => 'recall'],
        ], json_decode($tool->attributes['langfuse.observation.output'], true));
    }

    public function testToolFinishOnTurnWithoutCompletionsKeepsTurnTraceWithoutParent(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-empty-turn');
        $thread->turns[] = Turn::of('turn-empty', [], model: 'main');

        $listener->onToolFinished(new ToolFinished(
            $this->createStub(ToolsInterface::class),
            new ToolCall('c-missing', 'bash', []),
            $this->agentFor($thread, 'conv-empty-turn'),
            new ToolResult('ok', 'c-missing', 'bash'),
        ));

        $span = $this->recording->observation();
        $this->assertSame(Otlp::traceId('turn-empty'), $span->traceId);
        $this->assertSame(Otlp::spanId(Otlp::traceId('turn-empty'), 'step-0'), $span->parentSpanId);
    }

    /**
     * A tool that finishes after the next turn opened still belongs to the turn that requested it: its
     * trace must not inherit the newer turn's dialogue, `turn_id`, or bounds.
     */
    public function testToolFinishingAfterNextTurnKeepsItsOwnTraceRoot(): void
    {
        $listener = $this->make(LlmListener::class);
        $call = new ToolCall('c1', 'read', ['path' => 'a.php']);

        $earlier = Completion::of(UserMessage::of('turn one question'));
        $earlier->output = new AssistantMessage('', [$call]);
        $thread = new Thread('conv-late-tool');
        $thread->turns[] = Turn::of('turn-one', [$earlier], TurnStatus::Running, 'main');

        $later = Completion::of(UserMessage::of('turn two question'));
        $later->output = new AssistantMessage('turn two answer');
        $thread->turns[] = Turn::of('turn-two', [$later], TurnStatus::Running, 'main');

        $listener->onToolFinished(new ToolFinished(
            $this->createStub(ToolsInterface::class),
            $call,
            $this->agentFor($thread, 'conv-late-tool'),
            new ToolResult('file body', 'c1', 'read', ToolTiming::of($call, 0.2)),
        ));

        $root = $this->recording->root();
        $this->assertSame(Otlp::traceId('turn-one'), $root->traceId);
        $this->assertSame('turn-one', $root->attributes['langfuse.trace.metadata.turn_id']);
        $this->assertStringContainsString('turn one question', (string) $root->attributes['langfuse.observation.input']);
        $this->assertArrayNotHasKey('langfuse.observation.output', $root->attributes);
        $this->assertSame('turn-one', $this->recording->step()->attributes['langfuse.trace.metadata.turn_id']);
    }

    public function testTraceRootKeepsObservationMetadataOffTheTrace(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-root');
        $completion = Completion::of(UserMessage::of('hi'));
        $completion->output = new AssistantMessage('hello');
        $completion->step = 4;
        $completion->finishReason = 'end_turn';
        $thread->turns[] = Turn::of('turn-root', [$completion], TurnStatus::Running, 'main');

        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $completion, Charter::of()));

        $root = $this->recording->root();
        $generation = $this->recording->observation();

        // Langfuse folds a root span's observation metadata into the trace, so the root must stay clean.
        $this->assertSame('span', $root->attributes['langfuse.observation.type']);
        foreach (array_keys($root->attributes) as $key) {
            $this->assertStringStartsNotWith('langfuse.observation.metadata', $key);
        }
        // The root mirrors the text reply; that value never changes, so repeating it is safe.
        $this->assertSame('hello', json_decode($root->attributes['langfuse.observation.output'], true));
        $this->assertSame('conv-root', $root->attributes['langfuse.trace.metadata.thread_id']);
        $this->assertSame('turn-root', $root->attributes['langfuse.trace.metadata.turn_id']);

        // The generation keeps the details, under its own span id, parented to that root.
        $this->assertSame('4', $generation->attributes['langfuse.observation.metadata.step']);
        $this->assertSame('end_turn', $generation->attributes['langfuse.observation.metadata.finish_reason']);
        $this->assertSame($this->recording->step()->spanId, $generation->parentSpanId);
        $this->assertSame($root->traceId, $generation->traceId);
    }

    public function testEveryExportCarriesTheSameStableRootIo(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-root-io');
        $call = new ToolCall('c1', 'web_search', ['query' => 'x']);
        $first = Completion::of(UserMessage::of('question'));
        $first->output = new AssistantMessage(null, [$call]);
        $first->timing = LlmTiming::of(wall: 1.0, start: 1_700_000_000.0);
        $turn = Turn::of('turn-root-io', [$first], TurnStatus::Running, 'main');
        $thread->turns[] = $turn;

        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $first, Charter::of()));

        // A tool finish exports the root again; Langfuse applies the last root write wholesale, so the
        // resend must still carry the conversation or the trace loses its input/output.
        $listener->onToolFinished(new ToolFinished(
            $this->createStub(ToolsInterface::class),
            $call,
            $this->agentFor($thread, 'conv-root-io'),
            new ToolResult('result', 'c1', 'web_search', ToolTiming::of($call, 0.2)),
        ));

        $second = Completion::of(ToolMessage::of([new ToolResult('result', 'c1', 'web_search')]));
        $second->output = new AssistantMessage('final answer');
        $second->timing = LlmTiming::of(wall: 1.0, start: 1_700_000_002.0);
        $turn->completions[] = $second;
        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $second, Charter::of()));

        $this->assertCount(3, $this->recording->ingests);

        // A resend without input clears it on Langfuse, so every export must repeat the conversation.
        foreach ([0, 1, 2] as $batch) {
            $root = $this->recording->root($batch);
            $this->assertSame(
                [['role' => 'user', 'content' => 'question']],
                json_decode($root->attributes['langfuse.observation.input'], true),
                "export $batch dropped the root input",
            );
            // The first two exports predate the text reply, so they carry input only.
            if ($batch < 2) {
                $this->assertArrayNotHasKey('langfuse.observation.output', $root->attributes);

                continue;
            }
            $this->assertSame(
                'final answer',
                json_decode($root->attributes['langfuse.observation.output'], true),
                "export $batch must carry the stable reply",
            );
        }
    }

    public function testEveryExportReusesOneRootSpanId(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-root-stable');
        $thread->turns[] = Turn::of('turn-stable', status: TurnStatus::Running, model: 'main');

        $first = Completion::of(UserMessage::of('one'));
        $first->output = new AssistantMessage('a');
        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $first, Charter::of()));

        $second = Completion::of(UserMessage::of('two'));
        $second->output = new AssistantMessage('b');
        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $second, Charter::of()));

        $this->assertSame($this->recording->root(0)->spanId, $this->recording->root(1)->spanId);
        $this->assertNotSame($this->recording->observation(0)->spanId, $this->recording->observation(1)->spanId);
    }

    public function testTraceNameUsesCatalogProfileOverThreadUuid(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('4f2c9a1e-0b77-4a53-9d61-8f0a2c7be915');
        $thread->state['profileId'] = 'coding.agent';
        $completion = Completion::of(UserMessage::of('hi'));
        $completion->output = new AssistantMessage('hello');
        $thread->turns[] = Turn::of('turn-profile', [$completion], TurnStatus::Running, 'main');

        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $completion, Charter::of()));

        $attributes = $this->recording->root()->attributes;
        $this->assertSame('coding.agent', $attributes['langfuse.trace.name']);
        $this->assertSame('coding.agent', $attributes['langfuse.trace.metadata.profile_id']);
        $this->assertSame(
            '4f2c9a1e-0b77-4a53-9d61-8f0a2c7be915',
            $attributes['langfuse.trace.metadata.thread_id'],
        );
    }

    public function testTraceNameFallsBackToThreadIdWithoutProfile(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-plain');
        $completion = Completion::of(UserMessage::of('hi'));
        $completion->output = new AssistantMessage('hello');
        $thread->turns[] = Turn::of('turn-plain', [$completion], TurnStatus::Running, 'main');

        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $completion, Charter::of()));

        $attributes = $this->recording->root()->attributes;
        $this->assertSame('conv-plain', $attributes['langfuse.trace.name']);
        $this->assertArrayNotHasKey('langfuse.trace.metadata.profile_id', $attributes);
    }

    public function testEverySpanCarriesEnvironmentAndRelease(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-env');
        $completion = Completion::of(UserMessage::of('hi'));
        $completion->output = new AssistantMessage('hello');
        $thread->turns[] = Turn::of('turn-env', [$completion], TurnStatus::Running, 'main');

        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $completion, Charter::of()));

        $attributes = $this->recording->root()->attributes;
        $this->assertArrayHasKey('langfuse.environment', $attributes);
        $this->assertArrayHasKey('langfuse.release', $attributes);
        $this->assertSame(
            $attributes['langfuse.environment'],
            $this->recording->observation()->attributes['langfuse.environment'],
        );
    }

    public function testGenerationRecordsReasoningEffortAsModelParameters(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-effort');
        $completion = Completion::of(UserMessage::of('hi'));
        $completion->output = new AssistantMessage('hello');
        $thread->turns[] = Turn::of('turn-effort', [$completion], TurnStatus::Running, 'main', effort: 'high');

        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $completion, Charter::of()));

        $this->assertSame(
            ['reasoning_effort' => 'high'],
            json_decode(
                $this->recording->observation()->attributes['langfuse.observation.model.parameters'],
                true,
            ),
        );
    }

    public function testFailureIsNamedAsFailureNotAnswer(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-fail-name');
        $completion = Completion::of(UserMessage::of('hi'));

        $listener->onCompletionFailed(new CompletionFailed(
            new StubLlm(),
            $thread,
            $completion,
            new RuntimeException('boom'),
            Charter::of(),
        ));

        $this->assertSame('failed-llm-call', $this->recording->observation()->name);
    }

    public function testTurnStructureBecomesOneAgentSpanPerStep(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-shape');
        $call = new ToolCall('c1', 'web_search', ['query' => 'x']);
        $step1 = Completion::of(UserMessage::of('question'));
        $step1->output = new AssistantMessage(null, [$call]);
        $step1->step = 1;
        $step2 = Completion::of(ToolMessage::of([new ToolResult('r', 'c1', 'web_search')]));
        $step2->output = new AssistantMessage('done');
        $step2->step = 2;
        $thread->turns[] = Turn::of('turn-shape', [$step1, $step2], TurnStatus::Running, 'main');

        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $step1, Charter::of()));
        $listener->onToolFinished(new ToolFinished(
            $this->createStub(ToolsInterface::class),
            $call,
            $this->agentFor($thread, 'conv-shape'),
            new ToolResult('r', 'c1', 'web_search', ToolTiming::of($call, 0.2)),
        ));
        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $step2, Charter::of()));

        $rootId = $this->recording->root(0)->spanId;

        // Each export carries the same root, the step that owns the work, then the observation.
        foreach ([0, 1, 2] as $batch) {
            $root = $this->recording->root($batch);
            $step = $this->recording->step($batch);
            $observation = $this->recording->observation($batch);

            $this->assertSame('turn', $root->name);
            $this->assertSame('agent', $step->attributes['langfuse.observation.type']);
            $this->assertSame($rootId, $step->parentSpanId);
            $this->assertSame($step->spanId, $observation->parentSpanId);
        }

        // Step 1 owns the tool call and the generation that requested it, as siblings.
        $this->assertSame('step-1', $this->recording->step(0)->name);
        $this->assertSame('step-1', $this->recording->step(1)->name);
        $this->assertSame('1', $this->recording->step(0)->attributes['langfuse.observation.metadata.step']);

        // The second model call is its own step, so the tree shows what changed after the tool result.
        $this->assertSame('step-2', $this->recording->step(2)->name);
        $this->assertSame('2', $this->recording->step(2)->attributes['langfuse.observation.metadata.step']);

        // A step span is re-sent under one id, so it upserts instead of duplicating per tool.
        $this->assertSame($this->recording->step(0)->spanId, $this->recording->step(1)->spanId);
        $this->assertNotSame($this->recording->step(0)->spanId, $this->recording->step(2)->spanId);
    }

    public function testTurnAttributesBecomeTraceUserTagsAndMetadata(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-turn-attrs');
        $completion = Completion::of(UserMessage::of('hi'));
        $completion->output = new AssistantMessage('hello');
        $turn = Turn::of('turn-app', [$completion], TurnStatus::Running, 'main');
        $turn->attributes = [
            'user_id' => 'doc-7',
            'tags' => ['assist', ' night '],
            'department_id' => 'cardiology',
            'thread_id' => 'not-the-thread',
        ];
        $thread->turns[] = $turn;

        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $completion, Charter::of()));

        $attributes = $this->recording->root()->attributes;
        $this->assertSame('doc-7', $attributes['langfuse.user.id']);
        $this->assertSame(['assist', 'night'], $attributes['langfuse.trace.tags']);
        $this->assertSame('cardiology', $attributes['langfuse.trace.metadata.department_id']);
        $this->assertSame('conv-turn-attrs', $attributes['langfuse.trace.metadata.thread_id']);
        $this->assertSame('not-the-thread', $attributes['langfuse.trace.metadata.attr.thread_id']);
        $this->assertArrayNotHasKey('langfuse.trace.metadata.user_id', $attributes);
        $this->assertArrayNotHasKey('langfuse.trace.metadata.tags', $attributes);
    }

    public function testAppAttributesStayFilterableUnlessTheyCollide(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-attrs');
        $completion = Completion::of(UserMessage::of('hi'));
        $completion->output = new AssistantMessage('hello');
        $completion->step = 3;
        // `tenant_id` is free to use; `step` collides with the real step number and must not win.
        $completion->attributes = ['tenant_id' => 'acme', 'step' => 'app-value'];
        $thread->turns[] = Turn::of('turn-attrs', [$completion], TurnStatus::Running, 'main');

        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $completion, Charter::of()));

        $attributes = $this->recording->observation()->attributes;
        $this->assertSame('acme', $attributes['langfuse.observation.metadata.tenant_id']);
        $this->assertSame('3', $attributes['langfuse.observation.metadata.step']);
        $this->assertSame('app-value', $attributes['langfuse.observation.metadata.attr.step']);
    }

    public function testToolAppAttributesDoNotOverwriteToolWiring(): void
    {
        $listener = $this->make(LlmListener::class);
        $call = new ToolCall('c1', 'read', ['path' => 'a.php']);
        $call->attributes = ['attempt' => 2];
        $completion = Completion::of(UserMessage::of('go'));
        $completion->output = new AssistantMessage('', [$call]);
        $thread = new Thread('conv-tool-attrs');
        $thread->turns[] = Turn::of('turn-tool-attrs', [$completion], model: 'main');

        $listener->onToolFinished(new ToolFinished(
            $this->createStub(ToolsInterface::class),
            $call,
            $this->agentFor($thread, 'conv-tool-attrs'),
            new ToolResult('body', 'c1', 'read', ToolTiming::of($call, 0.2), attributes: ['tool_call_id' => 'hijack']),
        ));

        $attributes = $this->recording->observation()->attributes;
        $this->assertSame('2', $attributes['langfuse.observation.metadata.attempt']);
        $this->assertSame('c1', $attributes['langfuse.observation.metadata.tool_call_id']);
        $this->assertSame('hijack', $attributes['langfuse.observation.metadata.attr.tool_call_id']);
    }

    public function testTurnMetadataCarriesStatusWindowAndTotals(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-turn-meta');
        $completion = Completion::of(UserMessage::of('hi'));
        $completion->output = new AssistantMessage('hello');
        $completion->usage = new TokenUsage(input: 10, output: 4);
        $completion->timing = LlmTiming::of(wall: 1.5, start: 1_700_000_000.0);
        $turn = Turn::of('turn-meta', [$completion], TurnStatus::Completed, 'main', effort: 'high');
        $turn->openedAt = 1_700_000_000.0;
        $turn->closedAt = 1_700_000_001.5;
        $thread->turns[] = $turn;

        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $completion, Charter::of()));

        $attributes = $this->recording->root()->attributes;
        $this->assertSame('completed', $attributes['langfuse.trace.metadata.status']);
        $this->assertSame(Otlp::isoFromUnix(1_700_000_000.0), $attributes['langfuse.trace.metadata.opened_at']);
        $this->assertSame(Otlp::isoFromUnix(1_700_000_001.5), $attributes['langfuse.trace.metadata.closed_at']);
        $this->assertSame('high', $attributes['langfuse.trace.metadata.effort']);
        $this->assertSame('1', $attributes['langfuse.trace.metadata.llm_calls']);
        $this->assertSame('1.5', $attributes['langfuse.trace.metadata.llm_wall']);
    }

    public function testDeliverySettledResendsTurnRootWithFinalStatus(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-settled');
        $completion = Completion::of(UserMessage::of('hi'));
        $completion->output = new AssistantMessage('hello');
        $turn = Turn::of('turn-settled', [$completion], TurnStatus::Completed, 'main');
        $turn->openedAt = 1_700_000_000.0;
        $turn->closedAt = 1_700_000_002.0;
        $thread->turns[] = $turn;

        $listener->onDeliverySettled(new \Switon\Ai\Event\DeliverySettled(
            $this->agentFor($thread, 'conv-settled'),
            $turn,
        ));

        $root = $this->recording->single();
        $this->assertSame('turn', $root->name);
        $this->assertSame(Otlp::traceId('turn-settled'), $root->traceId);
        $this->assertSame(Otlp::spanId(Otlp::traceId('turn-settled'), 'root'), $root->spanId);
        $this->assertSame('completed', $root->attributes['langfuse.trace.metadata.status']);
        $this->assertSame(Otlp::isoFromUnix(1_700_000_002.0), $root->attributes['langfuse.trace.metadata.closed_at']);
        $this->assertSame('hello', json_decode($root->attributes['langfuse.observation.output'], true));
    }

    public function testDeliverySettledIgnoresTurnWithoutId(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-settled-empty');
        $turn = Turn::of('', [], TurnStatus::Completed, 'main');
        $thread->turns[] = $turn;

        $listener->onDeliverySettled(new \Switon\Ai\Event\DeliverySettled(
            $this->agentFor($thread, 'conv-settled-empty'),
            $turn,
        ));

        $this->assertSame([], $this->recording->ingests);
    }

    public function testCacheEfficiencyRidesOnGenerationMetadata(): void
    {
        $listener = $this->make(LlmListener::class);
        $thread = new Thread('conv-cache');
        $completion = Completion::of(UserMessage::of('hi'));
        $completion->output = new AssistantMessage('hello');
        $completion->usage = new TokenUsage(input: 100, output: 10, cacheRead: 60, hit: 0.6, ideal: 0.8);
        $thread->turns[] = Turn::of('turn-cache', [$completion], TurnStatus::Running, 'main');

        $listener->onCompletionResponded(new CompletionResponded(new StubLlm(), $thread, $completion, Charter::of()));

        $attributes = $this->recording->observation()->attributes;
        $this->assertSame('0.6', $attributes['langfuse.observation.metadata.cache_hit']);
        $this->assertSame('0.8', $attributes['langfuse.observation.metadata.cache_ideal']);
    }

    public function testCompletionWithoutTurnUsesCompletionIdAsTrace(): void
    {
        $listener = $this->make(LlmListener::class);
        $completion = Completion::of(UserMessage::of('hi'));
        $completion->output = new AssistantMessage('hello');

        $listener->onCompletionResponded(new CompletionResponded(
            new StubLlm(),
            new Thread('conv-no-turn'),
            $completion,
            Charter::of(),
        ));

        $span = $this->recording->observation();
        $this->assertSame(Otlp::traceId($completion->completeId), $span->traceId);
        $this->assertArrayNotHasKey('langfuse.trace.metadata.turn_id', $span->attributes);
        $this->assertSame('conv-no-turn', $span->attributes['langfuse.trace.metadata.thread_id']);
    }

    public function testCompletionWithoutIdIsIgnored(): void
    {
        $listener = $this->make(LlmListener::class);
        $completion = Completion::of(UserMessage::of('hi'));
        $completion->completeId = '';

        $listener->onCompletionResponded(new CompletionResponded(
            new StubLlm(),
            new Thread('conv-no-id'),
            $completion,
            Charter::of(),
        ));

        $this->assertSame([], $this->recording->ingests);
    }

    protected function agentFor(Thread $thread, string $threadId): AgentInterface
    {
        $agent = $this->createStub(AgentInterface::class);
        $agent->method('thread')->willReturn($thread);
        $agent->method('threadId')->willReturn($threadId);

        return $agent;
    }
}
