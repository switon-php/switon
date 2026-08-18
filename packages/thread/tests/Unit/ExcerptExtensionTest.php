<?php

declare(strict_types=1);

namespace Switon\Thread\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Envelope;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\FoldedMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Part\PinnedPart;
use Switon\Ai\Part\RefusalPart;
use Switon\Ai\Part\TextPart;
use Switon\Ai\Profile;
use Switon\Ai\Signal\AssistantAbortSignal;
use Switon\Ai\Signal\UserSignal;
use Switon\Ai\Thread;
use Switon\Ai\TokenUsage;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Llm\LlmProvidersInterface;
use Switon\Thread\ExcerptExtension;
use Switon\Thread\ExcerptMessage;
use Switon\Thread\Tests\TestCase;
use Switon\Thread\Tests\Unit\Fixtures\StubLlm;
use Switon\Thread\Tests\Unit\Fixtures\StubLlmProviders;

use function array_column;

#[CoversClass(ExcerptExtension::class)]
#[Group('coroutine')]
class ExcerptExtensionTest extends TestCase
{
    protected StubLlm $llm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llm = new StubLlm();
        $this->container->set(LlmProvidersInterface::class, new StubLlmProviders($this->llm));
    }

    public function testBeforeTurnFoldsOlderRungsWhenOverLine(): void
    {
        $long = str_repeat('A', 120);
        $thread = new Thread('chat-1', [
            Turn::of('t0', [Completion::of(UserMessage::of('old-' . $long), new AssistantMessage('a0-' . $long))], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('prev-' . $long), new AssistantMessage('a1'))], TurnStatus::Completed),
        ]);
        $thread->turns[1]->completions[0]->usage = new TokenUsage(input: 120_000);
        $agent = new ProbeAgent($thread);

        $this->extension()->beforeTurn($agent, Turn::of('live', [Completion::of(UserMessage::of('now'))]));

        $this->assertInstanceOf(ExcerptMessage::class, $thread->turns[0]->completions[0]->input);
        $this->assertCount(2, $thread->turns);
        $this->assertSame('prev-' . $long, $thread->turns[1]->completions[0]->input->wire()[0]['text']);
        $this->assertTrue($agent->charter()->invalidated());
        $this->assertNull($thread->turns[1]->completions[0]->usage);
    }

    public function testBeforeTurnSkipsWhenLastTurnIsOpen(): void
    {
        $long = str_repeat('A', 120);
        $thread = new Thread('chat-1', [
            Turn::of('t0', [Completion::of(UserMessage::of('old-' . $long), new AssistantMessage('a0'))], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('prev-' . $long), new AssistantMessage('a1'))], TurnStatus::Running),
        ]);
        $thread->turns[1]->completions[0]->usage = new TokenUsage(input: 120_000);

        $this->extension()->beforeTurn(new ProbeAgent($thread), Turn::of('live'));

        $this->assertCount(2, $thread->turns);
        $this->assertSame('old-' . $long, $thread->turns[0]->completions[0]->input->text());
    }

    public function testBeforeTurnSkipsWhenUnderLine(): void
    {
        $thread = new Thread('chat-1', [
            Turn::of('t0', [Completion::of(UserMessage::of('old'), new AssistantMessage('a0'))], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('prev'), new AssistantMessage('a1'))], TurnStatus::Completed),
        ]);
        $thread->turns[1]->completions[0]->usage = new TokenUsage(input: 100);

        $this->extension()->beforeTurn(new ProbeAgent($thread), Turn::of('live'));

        $this->assertSame('old', $thread->turns[0]->completions[0]->input->wire()[0]['text']);
        $this->assertCount(2, $thread->turns);
    }

    public function testBeforeModelReplacesKeptTurnWithDialogueWhenFoldDoesNotShrink(): void
    {
        $bulk = str_repeat('T', 200);
        $thread = new Thread('chat-1', [
            $this->answeredTurn('t0', $bulk, 'done'),
            Turn::of('t1', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);
        $thread->turns[1]->completions[0]->usage = new TokenUsage(input: 122_000);

        $this->extension()->beforeModel(new ProbeAgent($thread), Charter::of());

        $kept = $thread->turns[0];
        $this->assertSame('t0', $kept->turnId);
        $this->assertCount(1, $kept->completions);
        $this->assertSame('ask', $kept->completions[0]->input->wire()[0]['text']);
        $this->assertInstanceOf(AssistantMessage::class, $kept->completions[0]->output);
        $this->assertSame('done', $kept->completions[0]->output->content);
        $this->assertSame([], $kept->completions[0]->output->toolCalls);
        $this->assertSame('now', $thread->turns[1]->completions[0]->input->wire()[0]['text']);
        $this->assertNull($thread->turns[1]->completions[0]->usage);
    }

    public function testBeforeModelLeavesReadonlyToolsUnderTheEmergencyLine(): void
    {
        $bulk = str_repeat('T', 200);
        $thread = new Thread('chat-1', [
            $this->toolTurn('t0', 'read', $bulk, TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);
        $thread->turns[1]->completions[0]->usage = new TokenUsage(input: 120_000);

        $this->extension()->beforeModel(new ProbeAgent($thread), Charter::of());

        $this->assertSame($bulk, $thread->turns[0]->completions[1]->input->results[0]->content);
    }

    public function testBeforeModelDoesNotCutTheOpenTurn(): void
    {
        $bulk = str_repeat('T', 200);
        $thread = new Thread('chat-1', [
            $this->toolTurn('t0', 'read', 'short', TurnStatus::Completed),
            $this->toolTurn('t1', 'read', $bulk, TurnStatus::Running),
        ]);
        $thread->turns[1]->completions[1]->usage = new TokenUsage(input: 122_000);

        $this->extension()->beforeModel(new ProbeAgent($thread), Charter::of());

        $this->assertSame($bulk, $thread->turns[1]->completions[1]->input->results[0]->content);
    }

    public function testBeforeModelLeavesATurnEndingOnAToolResult(): void
    {
        $thread = new Thread('chat-1', [
            Turn::of('t0', [
                Completion::of(UserMessage::of('ask'), new AssistantMessage('', [new ToolCall('c1', 'read', [])])),
                Completion::of(ToolMessage::of([new ToolResult('body', 'c1', 'read')])),
            ], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);
        $thread->turns[1]->completions[0]->usage = new TokenUsage(input: 122_000);

        $agent = new ProbeAgent($thread);
        $this->extension()->beforeModel($agent, Charter::of());

        $this->assertCount(2, $thread->turns[0]->completions);
        $this->assertSame('body', $thread->turns[0]->completions[1]->input->results[0]->content);
        $this->assertFalse($agent->charter()->invalidated());
    }

    public function testBeforeModelRewritesEveryKeptTurn(): void
    {
        $thread = new Thread('chat-1', [
            $this->answeredTurn('t0', str_repeat('A', 80), 'a'),
            $this->answeredTurn('t1', str_repeat('B', 80), 'b'),
            Turn::of('t2', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);
        $thread->turns[2]->completions[0]->usage = new TokenUsage(input: 122_000);

        $this->make(ExcerptExtension::class, ['keep' => 2])->beforeModel(new ProbeAgent($thread), Charter::of());

        $this->assertCount(1, $thread->turns[0]->completions);
        $this->assertCount(1, $thread->turns[1]->completions);
        $this->assertSame('a', $thread->turns[0]->completions[0]->output->content);
        $this->assertSame('b', $thread->turns[1]->completions[0]->output->content);
        $this->assertSame('now', $thread->turns[2]->completions[0]->input->wire()[0]['text']);
    }

    public function testBeforeModelFoldsAndSkipsToolCut(): void
    {
        $bulk = str_repeat('T', 200);
        $thread = new Thread('chat-1', [
            $this->toolTurn('t0', 'read', $bulk, TurnStatus::Completed),
            $this->toolTurn('t1', 'read', $bulk, TurnStatus::Completed),
            Turn::of('t2', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);
        $thread->turns[2]->completions[0]->usage = new TokenUsage(input: 122_000);

        $this->extension()->beforeModel(new ProbeAgent($thread), Charter::of());

        $this->assertInstanceOf(ExcerptMessage::class, $thread->turns[0]->completions[0]->input);
        $this->assertCount(3, $thread->turns);
        $this->assertSame($bulk, $thread->turns[1]->completions[1]->input->results[0]->content);
    }

    public function testBeforeModelCutsReadonlyBehindAForeignFold(): void
    {
        $bulk = str_repeat('T', 200);
        $thread = new Thread('chat-1', [
            Turn::of('head', [Completion::of(FoldedMessage::of('kept head'))], TurnStatus::Completed),
            $this->answeredTurn('t0', $bulk, 'done'),
            Turn::of('t1', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);
        $thread->turns[2]->completions[0]->usage = new TokenUsage(input: 122_000);

        $this->extension()->beforeModel(new ProbeAgent($thread), Charter::of());

        $this->assertSame('kept head', $thread->turns[0]->completions[0]->input->text());
        $this->assertCount(1, $thread->turns[1]->completions);
        $this->assertSame('ask', $thread->turns[1]->completions[0]->input->wire()[0]['text']);
        $this->assertSame('done', $thread->turns[1]->completions[0]->output->content);
    }

    public function testBeforeModelLeavesTheOpenTurnWhenItIsTheOnlyRung(): void
    {
        $bulk = str_repeat('T', 200);
        $thread = new Thread('chat-1', [
            $this->toolTurn('t0', 'read', $bulk, TurnStatus::Running),
        ]);

        $this->runBeforeModel($thread);

        $this->assertSame($bulk, $thread->turns[0]->completions[1]->input->results[0]->content);
        $this->assertCount(1, $thread->turns);
    }

    public function testBeforeModelLeavesAPlainKeptTurn(): void
    {
        $thread = new Thread('chat-1', [
            Turn::of('t0', [Completion::of(UserMessage::of('ask'), new AssistantMessage('answer'))], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);

        $agent = $this->runBeforeModel($thread);

        $this->assertSame('answer', $thread->turns[0]->completions[0]->output->content);
        $this->assertSame('t0', $thread->turns[0]->turnId);
        $this->assertNotNull($thread->turns[1]->completions[0]->usage);
        $this->assertFalse($agent->charter()->invalidated());
    }

    public function testBeforeModelKeepsTheAssistantReplyThatFollowedTools(): void
    {
        $thread = new Thread('chat-1', [
            Turn::of('t0', [
                Completion::of(UserMessage::of('ask'), new AssistantMessage('', [new ToolCall('c1', 'read', [])])),
                Completion::of(
                    ToolMessage::of([new ToolResult('SECRET', 'c1', 'read')]),
                    new AssistantMessage('the answer', [new ToolCall('c2', 'bash', [])]),
                ),
            ], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);

        $this->runBeforeModel($thread);

        $kept = $thread->turns[0];
        $this->assertCount(1, $kept->completions);
        $this->assertSame('ask', $kept->completions[0]->input->wire()[0]['text']);
        $this->assertSame('the answer', $kept->completions[0]->output->content);
        $this->assertSame('c2', $kept->completions[0]->output->toolCalls[0]->id);
        $this->assertStringNotContainsString('SECRET', $kept->completions[0]->input->wire()[0]['text'] . $kept->completions[0]->output->content);
    }

    public function testBeforeModelLeavesASignalTurn(): void
    {
        $thread = new Thread('chat-1', [
            Turn::of('finish', [
                Completion::of(
                    new UserSignal('task_finish', [], 'result body'),
                    new AssistantMessage('ack', [new ToolCall('c1', 'read', [])]),
                ),
                Completion::of(ToolMessage::of([new ToolResult('SECRET', 'c1', 'read')])),
            ], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);

        $this->runBeforeModel($thread);

        $input = $thread->turns[0]->completions[0]->input;
        $this->assertInstanceOf(UserSignal::class, $input);
        $this->assertSame('result body', $input->body);
        $this->assertCount(2, $thread->turns[0]->completions);
        $this->assertSame('SECRET', $thread->turns[0]->completions[1]->input->results[0]->content);
    }

    public function testBeforeModelRewritesTheKeptTurnBehindAnExcerptHead(): void
    {
        $thread = new Thread('chat-1', [
            Turn::of('head', [Completion::of(new ExcerptMessage([['role' => 'user', 'text' => 'older']], true))], TurnStatus::Completed),
            $this->answeredTurn('t0', str_repeat('T', 40), 'done'),
            Turn::of('t1', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);

        $this->runBeforeModel($thread);

        $this->assertInstanceOf(ExcerptMessage::class, $thread->turns[0]->completions[0]->input);
        $this->assertSame('older', $thread->turns[0]->completions[0]->input->items[0]['text']);
        $this->assertCount(1, $thread->turns[1]->completions);
        $this->assertSame('done', $thread->turns[1]->completions[0]->output->content);
        $this->assertSame('now', $thread->turns[2]->completions[0]->input->wire()[0]['text']);
    }

    public function testBeforeModelFoldsPastAnExcerptHeadAndLeavesTheKeptTools(): void
    {
        $bulk = str_repeat('T', 40);
        $thread = new Thread('chat-1', [
            Turn::of('head', [Completion::of(new ExcerptMessage([['role' => 'user', 'text' => 'older']], true))], TurnStatus::Completed),
            $this->toolTurn('old', 'read', $bulk, TurnStatus::Completed),
            $this->toolTurn('kept', 'read', $bulk, TurnStatus::Completed),
            Turn::of('tip', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);

        $this->runBeforeModel($thread);

        $this->assertInstanceOf(ExcerptMessage::class, $thread->turns[0]->completions[0]->input);
        $this->assertCount(3, $thread->turns);
        $this->assertSame('kept', $thread->turns[1]->turnId);
        $this->assertSame($bulk, $thread->turns[1]->completions[1]->input->results[0]->content);
        $this->assertSame('now', $thread->turns[2]->completions[0]->input->wire()[0]['text']);
    }

    public function testBeforeModelFoldsTheOnlyCompleteTurnWhenKeepIsZero(): void
    {
        $thread = new Thread('chat-1', [
            $this->toolTurn('t0', 'read', 'SECRET', TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);

        $this->runBeforeModel($thread, keep: 0);

        $this->assertInstanceOf(ExcerptMessage::class, $thread->turns[0]->completions[0]->input);
        $this->assertCount(2, $thread->turns);
        $this->assertSame('now', $thread->turns[1]->completions[0]->input->wire()[0]['text']);
        $this->assertStringNotContainsString('SECRET', $thread->turns[0]->completions[0]->input->wire()[0]['text']);
    }

    public function testBeforeModelDoesNothingWhenKeepIsZeroAndNothingCanFold(): void
    {
        $thread = new Thread('chat-1', [
            Turn::of('head', [Completion::of(new ExcerptMessage([['role' => 'user', 'text' => 'older']], true))], TurnStatus::Completed),
            $this->toolTurn('t1', 'read', 'SECRET', TurnStatus::Running),
        ]);

        $agent = $this->runBeforeModel($thread, keep: 0);

        $this->assertSame('SECRET', $thread->turns[1]->completions[1]->input->results[0]->content);
        $this->assertFalse($agent->charter()->invalidated());
    }

    public function testBeforeModelRewritesBothKeptTurnsBehindAnExcerptHead(): void
    {
        $thread = new Thread('chat-1', [
            Turn::of('head', [Completion::of(new ExcerptMessage([['role' => 'user', 'text' => 'older']], true))], TurnStatus::Completed),
            $this->answeredTurn('c0', 'one', 'first'),
            $this->answeredTurn('c1', 'two', 'second'),
            Turn::of('tip', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);

        $this->runBeforeModel($thread, keep: 2);

        $this->assertInstanceOf(ExcerptMessage::class, $thread->turns[0]->completions[0]->input);
        $this->assertSame('older', $thread->turns[0]->completions[0]->input->items[0]['text']);
        $this->assertCount(1, $thread->turns[1]->completions);
        $this->assertCount(1, $thread->turns[2]->completions);
        $this->assertSame('c0', $thread->turns[1]->turnId);
        $this->assertSame('first', $thread->turns[1]->completions[0]->output->content);
        $this->assertSame('c1', $thread->turns[2]->turnId);
        $this->assertSame('second', $thread->turns[2]->completions[0]->output->content);
        $this->assertSame('now', $thread->turns[3]->completions[0]->input->wire()[0]['text']);
    }

    public function testBeforeModelFoldsTheOldestWhenKeepLeavesTwoCompleteTurns(): void
    {
        $thread = new Thread('chat-1', [
            $this->toolTurn('old', 'read', 'SECRET', TurnStatus::Completed),
            $this->toolTurn('c0', 'bash', 'one', TurnStatus::Completed),
            $this->toolTurn('c1', 'read', 'two', TurnStatus::Completed),
            Turn::of('tip', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);

        $this->runBeforeModel($thread, keep: 2);

        $this->assertInstanceOf(ExcerptMessage::class, $thread->turns[0]->completions[0]->input);
        $this->assertCount(4, $thread->turns);
        $this->assertSame('c0', $thread->turns[1]->turnId);
        $this->assertSame('one', $thread->turns[1]->completions[1]->input->results[0]->content);
        $this->assertSame('two', $thread->turns[2]->completions[1]->input->results[0]->content);
        $this->assertStringNotContainsString('SECRET', $thread->turns[0]->completions[0]->input->wire()[0]['text']);
    }

    public function testBeforeModelClearsSettledToolsBehindAForeignFold(): void
    {
        $thread = new Thread('chat-1', [
            Turn::of('head', [Completion::of(FoldedMessage::of('kept head'))], TurnStatus::Completed),
            $this->answeredTurn('older', 'STAY', 'old answer'),
            $this->answeredTurn('newer', 'DROP', 'new answer'),
            Turn::of('tip', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);

        $this->runBeforeModel($thread, keep: 0);

        $this->assertSame('kept head', $thread->turns[0]->completions[0]->input->text());
        $this->assertCount(1, $thread->turns[1]->completions);
        $this->assertSame('old answer', $thread->turns[1]->completions[0]->output->content);
        $this->assertCount(1, $thread->turns[2]->completions);
        $this->assertSame('new answer', $thread->turns[2]->completions[0]->output->content);
        $this->assertSame('now', $thread->turns[3]->completions[0]->input->wire()[0]['text']);
    }

    public function testBeforeTurnSkipsWhenWindowIsZero(): void
    {
        $thread = new Thread('chat-1', [
            Turn::of('t0', [Completion::of(UserMessage::of('old'), new AssistantMessage('a0'))], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('prev'), new AssistantMessage('a1'))], TurnStatus::Completed),
        ]);
        $thread->turns[1]->completions[0]->usage = new TokenUsage(input: 120_000);
        $agent = new ProbeAgent($thread);
        $this->llm->window = 0;

        $this->extension()->beforeTurn($agent, Turn::of('live'));

        $this->assertCount(2, $thread->turns);
        $this->assertSame('old', $thread->turns[0]->completions[0]->input->wire()[0]['text']);
    }

    public function testBeforeModelSkipsWhenWindowIsZero(): void
    {
        $bulk = str_repeat('T', 40);
        $thread = new Thread('chat-1', [
            $this->toolTurn('t0', 'read', $bulk, TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);
        $thread->turns[1]->completions[0]->usage = new TokenUsage(input: 200_000);
        $agent = new ProbeAgent($thread);
        $this->llm->window = 0;

        $this->extension()->beforeModel($agent, Charter::of());

        $this->assertSame($bulk, $thread->turns[0]->completions[1]->input->results[0]->content);
    }

    public function testBeforeTurnSkipsJustUnderTheFoldLine(): void
    {
        $thread = new Thread('chat-1', [
            Turn::of('t0', [Completion::of(UserMessage::of('old'), new AssistantMessage('a0'))], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('prev'), new AssistantMessage('a1'))], TurnStatus::Completed),
        ]);
        $thread->turns[1]->completions[0]->usage = new TokenUsage(input: 102_399);

        $this->extension()->beforeTurn(new ProbeAgent($thread), Turn::of('live'));

        $this->assertCount(2, $thread->turns);
        $this->assertSame('old', $thread->turns[0]->completions[0]->input->wire()[0]['text']);
    }

    public function testBeforeTurnFoldsOnTheFoldLine(): void
    {
        $thread = new Thread('chat-1', [
            Turn::of('t0', [Completion::of(UserMessage::of('old'), new AssistantMessage('a0'))], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('prev'), new AssistantMessage('a1'))], TurnStatus::Completed),
        ]);
        $thread->turns[1]->completions[0]->usage = new TokenUsage(input: 102_400);

        $this->extension()->beforeTurn(new ProbeAgent($thread), Turn::of('live'));

        $this->assertInstanceOf(ExcerptMessage::class, $thread->turns[0]->completions[0]->input);
        $this->assertSame('prev', $thread->turns[1]->completions[0]->input->wire()[0]['text']);
    }

    public function testBeforeTurnFoldsJustOverTheFoldLine(): void
    {
        $thread = new Thread('chat-1', [
            Turn::of('t0', [Completion::of(UserMessage::of('old'), new AssistantMessage('a0'))], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('prev'), new AssistantMessage('a1'))], TurnStatus::Completed),
        ]);
        $thread->turns[1]->completions[0]->usage = new TokenUsage(input: 102_401);

        $this->extension()->beforeTurn(new ProbeAgent($thread), Turn::of('live'));

        $this->assertInstanceOf(ExcerptMessage::class, $thread->turns[0]->completions[0]->input);
        $this->assertSame('prev', $thread->turns[1]->completions[0]->input->wire()[0]['text']);
    }

    public function testBeforeModelSkipsJustUnderTheEmergencyLine(): void
    {
        $bulk = str_repeat('T', 40);
        $thread = new Thread('chat-1', [
            $this->toolTurn('t0', 'read', $bulk, TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);

        $this->runBeforeModel($thread, input: 121_599);

        $this->assertSame($bulk, $thread->turns[0]->completions[1]->input->results[0]->content);
    }

    public function testBeforeModelRewritesOnTheEmergencyLine(): void
    {
        $thread = new Thread('chat-1', [
            $this->answeredTurn('t0', str_repeat('T', 40), 'done'),
            Turn::of('t1', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);

        $this->runBeforeModel($thread, input: 121_600);

        $this->assertCount(1, $thread->turns[0]->completions);
        $this->assertSame('done', $thread->turns[0]->completions[0]->output->content);
    }

    public function testBeforeModelRewritesJustOverTheEmergencyLine(): void
    {
        $thread = new Thread('chat-1', [
            $this->answeredTurn('t0', str_repeat('T', 40), 'done'),
            Turn::of('t1', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);

        $this->runBeforeModel($thread, input: 121_601);

        $this->assertCount(1, $thread->turns[0]->completions);
        $this->assertSame('done', $thread->turns[0]->completions[0]->output->content);
    }

    public function testBeforeTurnDoesNotRewriteTheOnlyTurn(): void
    {
        $bulk = str_repeat('T', 40);
        $thread = new Thread('chat-1', [
            $this->toolTurn('t0', 'read', $bulk, TurnStatus::Completed),
        ]);
        $thread->turns[0]->completions[1]->usage = new TokenUsage(input: 120_000);

        $this->extension()->beforeTurn(new ProbeAgent($thread), Turn::of('live'));

        $this->assertCount(1, $thread->turns);
        $this->assertSame($bulk, $thread->turns[0]->completions[1]->input->results[0]->content);
    }

    public function testForceReturnsFalseWhenNothingCanFold(): void
    {
        $bulk = str_repeat('T', 40);
        $thread = new Thread('chat-1', [
            Turn::of('head', [Completion::of(new ExcerptMessage([['role' => 'user', 'text' => 'older']], true))], TurnStatus::Completed),
            $this->toolTurn('t0', 'read', $bulk, TurnStatus::Completed),
        ]);
        $agent = new ProbeAgent($thread);

        $this->assertFalse($this->extension()->force($agent));
        $this->assertSame('older', $thread->turns[0]->completions[0]->input->items[0]['text']);
        $this->assertSame($bulk, $thread->turns[1]->completions[1]->input->results[0]->content);
        $this->assertFalse($agent->charter()->invalidated());
    }

    public function testForceReturnsFalseForAnEmptyThread(): void
    {
        $this->assertFalse($this->extension()->force(new ProbeAgent(new Thread('chat-1'))));
    }

    public function testBeforeTurnSkipsAnEmptyThread(): void
    {
        $thread = new Thread('chat-1');

        $this->extension()->beforeTurn(new ProbeAgent($thread), Turn::of('live'));

        $this->assertSame([], $thread->turns);
    }

    public function testBeforeModelLeavesATurnEndingOnAnAbortSignal(): void
    {
        $thread = new Thread('chat-1', [
            Turn::of('t0', [
                Completion::of(UserMessage::of('ask'), new AssistantMessage('going', [new ToolCall('c1', 'read', [])])),
                Completion::of(
                    ToolMessage::of([new ToolResult('SECRET', 'c1', 'read')]),
                    new AssistantAbortSignal(AssistantAbortSignal::KIND_EXCEPTION, body: 'boom'),
                ),
            ], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);

        $agent = $this->runBeforeModel($thread);

        $this->assertCount(2, $thread->turns[0]->completions);
        $this->assertSame('SECRET', $thread->turns[0]->completions[1]->input->results[0]->content);
        $this->assertInstanceOf(AssistantAbortSignal::class, $thread->turns[0]->completions[1]->output);
        $this->assertFalse($agent->charter()->invalidated());
    }

    public function testBeforeModelKeepsTurnIdentityAndAssistantDetail(): void
    {
        $assistant = new AssistantMessage('going', reasoning: 'thought', parts: [RefusalPart::of('no')]);
        $thread = new Thread('chat-1', [
            Turn::of('t0', [
                Completion::of(UserMessage::of('ask'), new AssistantMessage('', [new ToolCall('c1', 'read', [])])),
                Completion::of(ToolMessage::of([new ToolResult('SECRET', 'c1', 'read')]), $assistant),
            ], TurnStatus::Completed, 'openai/default'),
            Turn::of('t1', [Completion::of(UserMessage::of('now'), new AssistantMessage('going'))], TurnStatus::Running),
        ]);

        $this->runBeforeModel($thread);

        $kept = $thread->turns[0];
        $this->assertSame('t0', $kept->turnId);
        $this->assertSame(TurnStatus::Completed, $kept->status);
        $this->assertSame('openai/default', $kept->model);
        $output = $kept->completions[0]->output;
        $this->assertSame('thought', $output->reasoning);
        $this->assertCount(1, $output->parts);
        $this->assertSame('no', $output->parts[0]->refusal);
        $this->assertSame([], $output->toolCalls);
    }

    public function testBeforeTurnIgnoresUsageOnAnOlderTurn(): void
    {
        $long = str_repeat('A', 120);
        $thread = new Thread('chat-1', [
            Turn::of('t0', [Completion::of(UserMessage::of('old-' . $long), new AssistantMessage('a0'))], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('prev-' . $long), new AssistantMessage('a1'))], TurnStatus::Completed),
        ]);
        $thread->turns[0]->completions[0]->usage = new TokenUsage(input: 120_000);

        $this->extension()->beforeTurn(new ProbeAgent($thread), Turn::of('live'));

        $this->assertCount(2, $thread->turns);
        $this->assertSame('old-' . $long, $thread->turns[0]->completions[0]->input->text());
    }

    public function testBeforeModelIgnoresUsageOnAnEarlierCompletion(): void
    {
        $bulk = str_repeat('T', 200);
        $thread = new Thread('chat-1', [
            Turn::of('t0', [
                Completion::of(UserMessage::of('ask'), new AssistantMessage('going', [new ToolCall('c1', 'bash', [])])),
                Completion::of(ToolMessage::of([new ToolResult($bulk, 'c1', 'bash')])),
            ], TurnStatus::Running),
        ]);
        $thread->turns[0]->completions[0]->usage = new TokenUsage(input: 120_000);

        $this->extension()->beforeModel(new ProbeAgent($thread), Charter::of());

        $this->assertSame($bulk, $thread->turns[0]->completions[1]->input->results[0]->content);
    }

    public function testFoldKeepsAskAndFinalAssistant(): void
    {
        $long = str_repeat('Z', 120);
        $thread = new Thread('chat-1', [
            Turn::of('finish', [
                Completion::of(new UserSignal('task_finish', [], 'brief headline keep'), new AssistantMessage('ack')),
            ], TurnStatus::Completed),
            Turn::of('u0', [
                Completion::of(UserMessage::of('ask', [TextPart::of('skill-body-' . $long)]), new AssistantMessage('a0')),
            ], TurnStatus::Completed),
            Turn::of('u1', [
                Completion::of(UserMessage::of('latest'), new AssistantMessage('done')),
            ], TurnStatus::Completed),
        ]);

        $thread->turns[2]->completions[0]->usage = new TokenUsage(input: 120_000);
        $this->extension()->beforeTurn(new ProbeAgent($thread), Turn::of('live'));
        $head = $thread->turns[0]->completions[0]->input;
        $this->assertInstanceOf(ExcerptMessage::class, $head);
        $this->assertSame(
            [
                ['role' => 'signal', 'text' => '[task_finish] brief headline keep'],
                ['role' => 'asst', 'text' => 'ack'],
                ['role' => 'user', 'text' => 'ask'],
                ['role' => 'asst', 'text' => 'a0'],
            ],
            $head->items,
        );
        $this->assertStringNotContainsString('skill-body-', $head->wire()[0]['text']);
    }

    public function testForceSkipsWhenLastTurnIsOpen(): void
    {
        $thread = new Thread('chat-1', [
            Turn::of('t0', [Completion::of(UserMessage::of('old'), new AssistantMessage('a0'))], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('prev'), new AssistantMessage('a1'))], TurnStatus::Running),
        ]);

        $this->assertFalse($this->extension()->force(new ProbeAgent($thread)));
        $this->assertCount(2, $thread->turns);
        $this->assertSame('old', $thread->turns[0]->completions[0]->input->text());
    }

    public function testFoldPreservesNewlinesWithinTheCut(): void
    {
        $code = "line1\n  indented\n" . str_repeat('z', 80);
        $thread = new Thread('chat-1', [
            Turn::of('t0', [Completion::of(UserMessage::of($code), new AssistantMessage('a0'))], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('keep'), new AssistantMessage('a1'))], TurnStatus::Completed),
        ]);

        $this->assertTrue($this->extension()->force(new ProbeAgent($thread)));
        $head = $thread->turns[0]->completions[0]->input;
        $this->assertInstanceOf(ExcerptMessage::class, $head);
        $this->assertStringContainsString("line1\n  indented\n", $head->wire()[0]['text']);
        $this->assertSame('keep', $thread->turns[1]->completions[0]->input->wire()[0]['text']);
    }

    public function testFoldCarriesPinnedPartsInLastSeenOrder(): void
    {
        $tail = new PinnedPart('skill:tail', 'tail-body');
        $thread = new Thread('chat-1', [
            Turn::of('t0', [Completion::of(
                UserMessage::of('old', [
                    new PinnedPart('skill:a', 'a1'),
                    new PinnedPart('skill:b', 'b1'),
                ]),
                new AssistantMessage('a0'),
            )], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(
                UserMessage::of('mid', [new class ('skill:a', 'a2') extends PinnedPart {
                }]),
                new AssistantMessage('a1'),
            )], TurnStatus::Completed),
            Turn::of('t2', [Completion::of(
                UserMessage::of('stay', [$tail]),
                new AssistantMessage('done'),
            )], TurnStatus::Completed),
        ]);

        $this->assertTrue($this->extension()->force(new ProbeAgent($thread)));
        $head = $thread->turns[0]->completions[0]->input;
        $this->assertInstanceOf(ExcerptMessage::class, $head);
        $this->assertSame(['skill:b', 'skill:a'], array_column($head->parts, 'id'));
        $this->assertSame('a2', $head->parts[1]->text);
        $this->assertSame($tail, $thread->turns[1]->completions[0]->input->parts[1]);
        $wire = $head->wire();
        $this->assertSame('b1', $wire[1]['text']);
        $this->assertSame('a2', $wire[2]['text']);
        $this->assertSame(['type', 'text'], array_keys($wire[1]));
        $this->assertStringNotContainsString('a2', $head->text() ?? '');

        $thread->turns[] = Turn::of('t3', [Completion::of(UserMessage::of('next'), new AssistantMessage('ack'))], TurnStatus::Completed);
        $this->assertTrue($this->extension()->force(new ProbeAgent($thread)));
        $head = $thread->turns[0]->completions[0]->input;
        $this->assertInstanceOf(ExcerptMessage::class, $head);
        $this->assertSame(['skill:b', 'skill:a', 'skill:tail'], array_column($head->parts, 'id'));
        $this->assertSame('tail-body', $head->parts[2]->text);
    }

    public function testFoldCarriesPinnedPartsFromToolResults(): void
    {
        $thread = new Thread('chat-1', [
            Turn::of('t0', [
                Completion::of(UserMessage::of('ask'), new AssistantMessage('', [new ToolCall('c1', 'skill_load', [])])),
                Completion::of(ToolMessage::of([
                    new ToolResult('loaded', 'c1', 'skill_load', parts: [
                        new PinnedPart('skill:demo', 'skill-body'),
                    ]),
                ])),
            ], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('stay'), new AssistantMessage('ack'))], TurnStatus::Completed),
        ]);

        $this->assertTrue($this->extension()->force(new ProbeAgent($thread)));
        $head = $thread->turns[0]->completions[0]->input;
        $this->assertInstanceOf(ExcerptMessage::class, $head);
        $this->assertSame(['skill:demo'], array_column($head->parts, 'id'));
        $this->assertSame('skill-body', $head->parts[0]->text);
    }

    public function testCatalogDropKeepsPinnedParts(): void
    {
        $pin = new PinnedPart('skill:a', 'body');
        $kept = [];
        for ($i = 0; $i < 6; $i++) {
            $kept[] = ['role' => 'user', 'text' => 'old-' . $i];
        }
        $thread = new Thread('chat-1', [
            Turn::of('head', [Completion::of(new ExcerptMessage($kept, true, [$pin]))], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('stay'), new AssistantMessage('ack'))], TurnStatus::Completed),
        ]);
        $thread->lastTurn()->lastCompletion()->usage = new TokenUsage(input: 122_000);

        $this->make(ExcerptExtension::class, ['catalog' => 0.003])->beforeModel(new ProbeAgent($thread), Charter::of());
        $head = $thread->turns[0]->completions[0]->input;
        $this->assertInstanceOf(ExcerptMessage::class, $head);
        $this->assertSame([$pin], $head->parts);
    }

    public function testFoldCutsHeadItems(): void
    {
        $long = str_repeat('x', 120);
        $thread = new Thread('chat-1', [
            Turn::of('t0', [Completion::of(UserMessage::of('u0-' . $long), new AssistantMessage('a0-' . $long))], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('u1-' . $long), new AssistantMessage('a1'))], TurnStatus::Completed),
        ]);
        $this->assertTrue($this->extension()->force(new ProbeAgent($thread)));
        $head = $thread->turns[0]->completions[0]->input;
        $this->assertInstanceOf(ExcerptMessage::class, $head);
        $this->assertSame(['user', 'asst'], array_column($head->items, 'role'));
        $this->assertSame(64, mb_strlen($head->items[0]['text']));
        $this->assertStringStartsWith('u0-', $head->items[0]['text']);
    }

    public function testFoldKeepsCatalogPastTheLimit(): void
    {
        $kept = [];
        for ($i = 0; $i < 6; $i++) {
            $kept[] = ['role' => 'user', 'text' => 'old-' . $i];
        }
        $thread = new Thread('chat-1', [
            Turn::of('head', [Completion::of(new ExcerptMessage($kept, true))], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('new-ask'), new AssistantMessage('new-ack'))], TurnStatus::Completed),
            Turn::of('t2', [Completion::of(UserMessage::of('stay'), new AssistantMessage('stay-ack'))], TurnStatus::Completed),
        ]);

        $this->assertTrue($this->make(ExcerptExtension::class, ['catalog' => 0.003])->force(new ProbeAgent($thread)));
        $head = $thread->turns[0]->completions[0]->input;
        $this->assertInstanceOf(ExcerptMessage::class, $head);
        $this->assertSame(
            ['old-0', 'old-1', 'old-2', 'old-3', 'old-4', 'old-5', 'new-ask', 'new-ack'],
            array_column($head->items, 'text'),
        );
        $this->assertTrue($head->dropped);
    }

    public function testBeforeModelDropsOldestThirdAtCatalogLimit(): void
    {
        $kept = [];
        for ($i = 0; $i < 6; $i++) {
            $kept[] = ['role' => 'user', 'text' => 'old-' . $i];
        }
        $thread = new Thread('chat-1', [
            Turn::of('head', [Completion::of(new ExcerptMessage($kept, true))], TurnStatus::Completed),
            Turn::of('t1', [Completion::of(UserMessage::of('stay'), new AssistantMessage('stay-ack'))], TurnStatus::Completed),
        ]);
        $thread->lastTurn()->lastCompletion()->usage = new TokenUsage(input: 122_000);

        $this->make(ExcerptExtension::class, ['catalog' => 0.003])->beforeModel(new ProbeAgent($thread), Charter::of());
        $head = $thread->turns[0]->completions[0]->input;
        $this->assertInstanceOf(ExcerptMessage::class, $head);
        $this->assertSame(
            ['old-2', 'old-3', 'old-4', 'old-5'],
            array_column($head->items, 'text'),
        );
        $this->assertTrue($head->dropped);
        $this->assertSame('stay', $thread->turns[1]->completions[0]->input->text());
    }

    protected function extension(): ExcerptExtension
    {
        return $this->make(ExcerptExtension::class);
    }

    protected function runBeforeModel(Thread $thread, int $keep = 1, int $input = 122_000): ProbeAgent
    {
        $thread->lastTurn()->lastCompletion()->usage = new TokenUsage(input: $input);
        $agent = new ProbeAgent($thread);
        $this->make(ExcerptExtension::class, ['keep' => $keep])->beforeModel($agent, Charter::of());

        return $agent;
    }

    protected function answeredTurn(string $id, string $content, string $answer): Turn
    {
        return Turn::of($id, [
            Completion::of(
                UserMessage::of('ask'),
                new AssistantMessage('going', [new ToolCall('c1', 'read', ['path' => 'a'])]),
            ),
            Completion::of(
                ToolMessage::of([new ToolResult($content, 'c1', 'read')]),
                new AssistantMessage($answer),
            ),
        ], TurnStatus::Completed);
    }

    protected function toolTurn(string $id, string $name, string $content, TurnStatus $status): Turn
    {
        return Turn::of($id, [
            Completion::of(
                UserMessage::of('ask'),
                new AssistantMessage('going', [new ToolCall('c1', $name, ['path' => 'a'])]),
            ),
            Completion::of(ToolMessage::of([new ToolResult($content, 'c1', $name)])),
        ], $status);
    }
}

class ProbeAgent implements \Switon\Ai\AgentInterface
{
    public Charter $boundCharter;

    public function __construct(public Thread $bound)
    {
        $this->boundCharter = Charter::of(prompt: 'prompt');
    }

    public function threadId(): string
    {
        return $this->bound->id;
    }

    public function depth(): int
    {
        return 0;
    }

    public function parent(): ?\Switon\Ai\AgentInterface
    {
        return null;
    }

    public function root(): \Switon\Ai\AgentInterface
    {
        return $this;
    }

    public function workspace(): string
    {
        return '';
    }

    public function thread(): Thread
    {
        return $this->bound;
    }

    public function profile(): Profile
    {
        return new Profile('probe');
    }

    public function charter(): Charter
    {
        return $this->boundCharter;
    }

    public function spawn(Profile $profile, ?string $leaf = null): static
    {
        return $this;
    }

    public function switchProfile(Profile $profile): void
    {
    }

    public function start(): void
    {
    }

    public function shutdown(): void
    {
    }

    public function isIdle(): bool
    {
        return true;
    }

    public function deliver(Envelope $envelope): void
    {
    }

    public function run(Envelope $envelope): ?Turn
    {
        return null;
    }

    public function cancel(): void
    {
    }

    public function isCancelled(): bool
    {
        return false;
    }

    public function onDelta(\Switon\Ai\StreamDelta $delta): void
    {
    }
}
