<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\TurnStatus;
use Switon\Core\Json;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Completion;
use Switon\Ai\Thread;
use Switon\Ai\Turn;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\ToolCall;

#[CoversClass(Thread::class)]
class ThreadTest extends TestCase
{
    public function testOfUsesProvidedId(): void
    {
        $thread = Thread::of('host.leaf', '@threads/chat-1');

        $this->assertSame('host.leaf', $thread->id);
        $this->assertSame('@threads/chat-1', $thread->datadir);
    }

    public function testOfDefaultsDatadirToNull(): void
    {
        $thread = Thread::of('memory-1');

        $this->assertSame('memory-1', $thread->id);
        $this->assertNull($thread->datadir);
    }

    public function testOfKeepsEmptyDatadir(): void
    {
        $thread = Thread::of('id', '');

        $this->assertSame('', $thread->datadir);
    }

    public function testOfPreservesState(): void
    {
        $thread = new Thread('chat-1', state: ['cursor' => 3]);

        $this->assertSame(['cursor' => 3], $thread->state);
    }

    public function testEmptyIdIsAccepted(): void
    {
        $thread = new Thread('');

        $this->assertSame('', $thread->id);
        $this->assertSame([], $thread->turns);
    }

    public function testEncodeLinesRoundTripsTurns(): void
    {
        $thread = new Thread('chat-abc', turns: [Turn::of('t1', [
                Completion::of(UserMessage::of('Hi'), new AssistantMessage('Hello')),
            ], TurnStatus::Completed),
        ]);

        $lines = array_map(
            static fn (Turn $turn): string => Json::stringify($turn),
            $thread->turns,
        );
        $loaded = new Thread('chat-abc', array_map(
            Turn::decode(...),
            array_map(Json::parse(...), $lines),
        ));

        $this->assertSame('chat-abc', $loaded->id);
        $messages = [];
        foreach ($loaded->completions() as $completion) {
            $messages[] = $completion->input;
            if ($completion->output !== null) {
                $messages[] = $completion->output;
            }
        }
        $this->assertSame('Hi', $messages[0]->wire()[0]['text']);
        $this->assertSame('Hello', $messages[1]->content);
    }

    public function testTipAccessors(): void
    {
        $first = Completion::of(UserMessage::of('a'), new AssistantMessage('b'));
        $last = Completion::of(UserMessage::of('c'), new AssistantMessage('d'));
        $thread = new Thread('chat-1', turns: [Turn::of('t1', [$first], TurnStatus::Completed),
            Turn::of('t2', [$last], TurnStatus::Running),
        ]);

        $this->assertSame('t1', $thread->firstTurn()->turnId);
        $this->assertSame('t2', $thread->lastTurn()->turnId);
        $this->assertSame($first, $thread->firstCompletion());
        $this->assertSame($last, $thread->lastCompletion());
    }

    public function testRunningTurnIsLastWhenStatusRunning(): void
    {
        $thread = new Thread('chat-1', turns: [Turn::of('t1', [
                Completion::of(UserMessage::of('done'), new AssistantMessage('ok')),
            ], TurnStatus::Completed),
            Turn::of('t2', status: TurnStatus::Running),
        ]);

        $this->assertSame('t2', $thread->lastTurn()?->turnId);
        $this->assertFalse($thread->lastTurn()?->isSettled() ?? true);
        $thread->turns[1]->status = TurnStatus::Completed;
        $this->assertSame('t2', $thread->lastTurn()?->turnId);
        $this->assertTrue($thread->lastTurn()?->isSettled() ?? false);
        $this->assertSame(TurnStatus::Completed, $thread->turns[1]->status);
    }

    public function testCloneDeepCopiesTurnGraph(): void
    {
        $call = new ToolCall('call_1', 'echo', ['value' => 'pong', 'nested' => ['a' => 1]]);
        $completion = Completion::of(UserMessage::of('Hi'), new AssistantMessage('Hello', [$call]));
        $completion->completeId = 'test-id';
        $completion->attributes = ['meta' => ['k' => 'v']];
        $thread = new Thread('chat-1', turns: [Turn::of('t1', [
                $completion,
            ], TurnStatus::Completed),
        ], state: ['cursor' => ['n' => 1]]);

        $copy = clone $thread;
        $copy->turns[0]->completions[0]->input->parts[0]->text = 'Changed input';
        $copy->turns[0]->completions[0]->output->content = 'Changed';
        $copy->turns[0]->completions[0]->output->toolCalls[0]->arguments['value'] = 'mutated';
        $copy->turns[0]->completions[0]->output->toolCalls[0]->arguments['nested']['a'] = 9;
        $copy->turns[0]->completions[0]->attributes['meta']['k'] = 'x';
        $copy->state['cursor']['n'] = 2;

        $this->assertSame('chat-1', $copy->id);
        $this->assertSame('Hi', $thread->turns[0]->completions[0]->input->wire()[0]['text']);
        $this->assertSame('Hello', $thread->turns[0]->completions[0]->output->content);
        $this->assertSame('pong', $thread->turns[0]->completions[0]->output->toolCalls[0]->arguments['value']);
        $this->assertSame(1, $thread->turns[0]->completions[0]->output->toolCalls[0]->arguments['nested']['a']);
        $this->assertSame('v', $thread->turns[0]->completions[0]->attributes['meta']['k']);
        $this->assertSame(1, $thread->state['cursor']['n']);
        $this->assertNotSame($thread->turns[0], $copy->turns[0]);
        $this->assertNotSame($thread->turns[0]->completions[0], $copy->turns[0]->completions[0]);
    }
}
