<?php

declare(strict_types=1);

namespace Switon\Thread\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\AsToolCall;
use Switon\Ai\AsToolResult;
use Switon\Ai\Completion;
use Switon\Ai\LlmTiming;
use Switon\Ai\Message;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\FoldedMessage;
use Switon\Ai\Message\SystemMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Signal\UserSignal;
use Switon\Ai\Suspension\RetryInterrupt;
use Switon\Ai\Thread;
use Switon\Ai\TokenUsage;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Ai\UsageTotals;
use Switon\Core\ClockInterface;
use Switon\Core\FilesystemInterface;
use Switon\Core\Json;
use Switon\Core\Strings;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\MockClock;
use Switon\Thread\Event\CompletionAppended;
use Switon\Thread\Event\TurnCommitted;
use Switon\Thread\Exception;
use Switon\Thread\ExcerptMessage;
use Switon\Thread\Tests\TestCase;
use Switon\Thread\Threads;
use Switon\Thread\ThreadsInterface;

#[CoversClass(Threads::class)]
class ThreadsTest extends TestCase
{
    public function testCreateAcceptsHostAndSubDatadir(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $host = 'host-1';
        $thread = Thread::of($host, '@threads/' . $host);
        $this->threadStore()->create($thread);
        $this->assertTrue($this->container->get(FilesystemInterface::class)->isDir($this->threadLeafDir($host)));

        $host = '8cc9d00d-aaaa-bbbb-cccc-dddddddddddd';
        $threadId = $host . '.t10001';
        $datadir = $this->threadDir($threadId);
        $this->assertSame('@threads/' . $host . '/subagents/t10001', $datadir);
        $sub = Thread::of($threadId, $datadir);
        $this->threadStore()->create($sub);
        $this->assertTrue($this->container->get(FilesystemInterface::class)->isDir($this->threadLeafDir($threadId)));
    }

    public function testMemoryThreadSkipsDisk(): void
    {
        $threads = $this->threadsImpl();
        $thread = Thread::of('memory-1');
        $threads->create($thread);
        $thread->state = ['profileId' => 'instructor'];
        $completion = Completion::of(UserMessage::of('ask'), new AssistantMessage('answer'));
        $completion->completeId = 'c1';
        $completion->step = 1;
        $this->threadAppend($thread, $completion);
        $this->threadCommit($thread);
        $threads->saveState($thread);

        $this->assertNull($thread->datadir);
        $this->assertSame('instructor', $thread->state['profileId']);
        $this->assertSame('answer', $thread->lastTurn()->lastCompletion()->output->text());
        $this->assertFileDoesNotExist('/conversation.jsonl');
    }

    public function testCreateStartsEmptyConversation(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $threadId = 'host-1';
        $thread = Thread::of($threadId, '@threads/' . $threadId);
        $this->threadStore()->create($thread);
        $dir = $this->threadLeafDir($threadId);

        $this->assertNotSame('', $threadId);
        $this->assertSame([], $thread->turns);
        $filesystem = $this->container->get(FilesystemInterface::class);
        $this->assertTrue($filesystem->isDir($dir));
        $this->assertTrue($filesystem->exists($dir . '/transcript.md'));
        $this->assertFalse($filesystem->exists($dir . '/conversation.jsonl'));
    }

    public function testLoadAndReplaceConversation(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $filesystem = $this->container->get(FilesystemInterface::class);
        $dir = $this->threadLeafDir('chat-1');
        $filesystem->mkdir($dir);
        $filesystem->write(
            $dir . '/conversation.jsonl',
            Json::stringify(Turn::of('t-hello', [
                Completion::of(UserMessage::of('hello'), new AssistantMessage('hi')),
            ], TurnStatus::Completed)) . "\n",
        );

        $threads = $this->threadsImpl();

        $this->assertSame('hello', $this->flattenWireMessages($this->threadLoad('chat-1')->completions())[0]->wire()[0]['text']);

        $threads->replaceConversation(new Thread('chat-1', [
            Turn::of('t-sum', [
                Completion::of(SystemMessage::of('summary'), new AssistantMessage('folded')),
            ], TurnStatus::Completed),
            Turn::of('t-tail', [
                Completion::of(UserMessage::of('tail'), new AssistantMessage('reply')),
            ], TurnStatus::Completed),
        ], [], '@threads/chat-1'));

        $lines = array_values(array_filter(
            Strings::rawLines($filesystem->read($dir . '/conversation.jsonl')),
            static fn (string $line): bool => trim($line) !== '',
        ));
        $this->assertCount(2, $lines);
        $this->assertSame('summary', Json::parse($lines[0])['completions'][0]['input']['content']);
        $this->assertArrayNotHasKey('thread_id', Json::parse($lines[0])['completions'][0]['input']);
        $this->assertSame('tail', Json::parse($lines[1])['completions'][0]['input']['parts'][0]['text']);
        // Reading never writes: a leaf without `state.json` loads as empty state.
        $this->assertFalse($filesystem->exists($dir . '/state.json'));
        $this->assertSame([], $this->threadLoad('chat-1')->state);
    }

    public function testCommitOpenTurnKeepsReasoningInWorkingContext(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $threads = $this->threadsImpl();
        $thread = Thread::of('chat-scrub', '@threads/chat-scrub');
        $this->threadStore()->create($thread);
        $c_c14 = Completion::of(UserMessage::of('ask'), new AssistantMessage('answer', reasoning: 'secret think'));
        $c_c14->completeId = 'c1';
        $c_c14->step = 1;
        $this->threadAppend(
            $thread,
            $c_c14,
        );
        $this->threadCommit($thread);

        $this->assertSame('secret think', $thread->turns[0]->completions[0]->output->reasoning);

        $dir = $this->threadLeafDir('chat-scrub');
        $filesystem = $this->container->get(FilesystemInterface::class);
        $transcript = $filesystem->read($dir . '/transcript.jsonl');
        $working = $filesystem->read($dir . '/conversation.jsonl');
        $this->assertStringContainsString('secret think', $transcript);
        $this->assertStringContainsString('secret think', $working);
    }

    public function testReplaceDropsPoppedSettledTipFromConversationOnly(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $threads = $this->threadsImpl();
        $thread = Thread::of('chat-discard-turn', '@threads/chat-discard-turn');
        $this->threadStore()->create($thread);
        $t0 = Completion::of(UserMessage::of('keep'), new AssistantMessage('ok'));
        $t0->completeId = 'k1';
        $t0->step = 1;
        $thread->turns[] = \Switon\Ai\Turn::of('t0', [$t0], TurnStatus::Running, 'main');
        $this->threadAppend($thread, $t0);
        $this->threadCommit($thread);
        $t1 = Completion::of(UserMessage::of('tail'), new AssistantMessage('bye'));
        $t1->completeId = 'x1';
        $t1->step = 1;
        $thread->turns[] = \Switon\Ai\Turn::of('t1', [$t1], TurnStatus::Running, 'main');
        $this->threadAppend($thread, $t1);
        $this->threadCommit($thread);

        array_pop($thread->turns);
        $threads->replaceConversation($thread);
        $this->assertCount(1, $thread->turns);
        $this->assertSame('t0', $thread->turns[0]->turnId);

        $dir = $this->threadLeafDir('chat-discard-turn');
        $filesystem = $this->container->get(FilesystemInterface::class);
        $conversationLines = array_values(array_filter(
            Strings::rawLines($filesystem->read($dir . '/conversation.jsonl')),
            static fn (string $line): bool => trim($line) !== '',
        ));
        $transcript = array_values(array_filter(
            Strings::rawLines($filesystem->read($dir . '/transcript.jsonl')),
            static fn (string $line): bool => trim($line) !== '',
        ));
        $this->assertCount(1, $conversationLines);
        $this->assertCount(2, $transcript);
    }

    public function testAppendAddsConversationAndTranscript(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $this->threadStore()->create(Thread::of('chat-1', '@threads/chat-1'));
        $threads = $this->threadsImpl();
        $dir = $this->threadLeafDir('chat-1');
        $thread = new Thread('chat-1', [], [], '@threads/chat-1');

        $first = Completion::of(UserMessage::of('hello'), new AssistantMessage('hi'));
        $first->completeId = 'c-hello';
        $first->step = 1;
        $second = Completion::of(UserMessage::of('again'), new AssistantMessage('ok'));
        $second->completeId = 'c-again';
        $second->step = 1;
        $this->threadAppend($thread, $first);
        $this->threadCommit($thread);
        $this->threadAppend($thread, $second);
        $this->threadCommit($thread);

        $this->assertCount(2, $thread->turns);

        $filesystem = $this->container->get(FilesystemInterface::class);
        $lines = array_values(array_filter(
            Strings::rawLines($filesystem->read($dir . '/conversation.jsonl')),
            static fn (string $line): bool => trim($line) !== '',
        ));
        $this->assertCount(2, $lines);
        $this->assertSame('hello', Json::parse($lines[0])['completions'][0]['input']['parts'][0]['text']);
        $this->assertSame('again', Json::parse($lines[1])['completions'][0]['input']['parts'][0]['text']);
        $this->assertGreaterThan(0, Json::parse($lines[0])['completions'][0]['landedAt']);
        $this->assertGreaterThan(0, Json::parse($lines[1])['completions'][0]['landedAt']);
        $this->assertGreaterThanOrEqual(
            Json::parse($lines[0])['completions'][0]['landedAt'],
            Json::parse($lines[1])['completions'][0]['landedAt'],
        );

        $completions = $this->transcriptCompletions('chat-1');
        $this->assertCount(2, $completions);
        $this->assertSame('hello', $completions[0]->input->wire()[0]['text']);
        $this->assertSame('again', $completions[1]->input->wire()[0]['text']);

        $partial = [$completions[1]];
        $this->assertCount(1, $partial);
        $this->assertSame('again', $partial[0]->input->wire()[0]['text']);

        $text = $filesystem->read($dir . '/transcript.md');
        $this->assertStringContainsString('turn: ', $text);
        $this->assertStringContainsString("user: hello", $text);
        $this->assertStringContainsString("user: again", $text);
    }

    public function testCommitLandsTranscriptProjectionWithTools(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $this->threadStore()->create(Thread::of('chat-tools', '@threads/chat-tools'));
        $threads = $this->threadsImpl();
        $dir = $this->threadLeafDir('chat-tools');
        $thread = new Thread('chat-tools', [], [], '@threads/chat-tools');
        $filesystem = $this->container->get(FilesystemInterface::class);

        $ask = Completion::of(UserMessage::of('look up'), new AssistantMessage('checking', [new ToolCall('call_1', 'echo', ['value' => 'pong'])]));
        $ask->completeId = 'c-ask';
        $ask->step = 1;
        $this->threadAppend($thread, $ask);

        $tools = Completion::of(ToolMessage::of([new ToolResult('pong', 'call_1', 'echo')]), new AssistantMessage('done'));
        $tools->completeId = 'c-tools';
        $tools->step = 2;
        $this->threadAppend($thread, $tools);

        $this->assertTrue($filesystem->exists($dir . '/transcript.md'));
        $this->assertSame('', trim($filesystem->read($dir . '/transcript.md')));

        $this->threadCommit($thread);
        $turn = $thread->lastTurn();
        $turnId = $turn->turnId;

        $text = $filesystem->read($dir . '/transcript.md');
        $this->assertStringContainsString("turn: {$turnId}\nuser: look up", $text);
        $this->assertStringContainsString("asst: checking\n\nasst: done", $text);
        $this->assertStringContainsString(
            "tools: echo — recall: @thread/transcript-tool/{$turnId}.txt",
            $text,
        );
        $this->assertStringNotContainsString('"value":"pong"', $text);

        $spill = $filesystem->read($dir . '/transcript-tool/' . $turnId . '.txt');
        $this->assertSame("echo: {\"value\":\"pong\"}\npong\n", $spill);
    }

    public function testCommitLandsTranscriptProjectionForSignal(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $this->threadStore()->create(Thread::of('chat-signal', '@threads/chat-signal'));
        $threads = $this->threadsImpl();
        $dir = $this->threadLeafDir('chat-signal');
        $thread = new Thread('chat-signal', [], [], '@threads/chat-signal');

        $finish = Completion::of(new UserSignal(
            'task_finish',
            ['status' => 'completed', 'task_id' => 't1'],
            'implement auth for api',
        ), new AssistantMessage('ack'));
        $finish->completeId = 'c-finish';
        $finish->step = 1;
        $this->threadAppend($thread, $finish);
        $this->threadCommit($thread);

        $text = $this->container->get(FilesystemInterface::class)->read($dir . '/transcript.md');
        $this->assertStringContainsString(
            'signal: [task_finish] implement auth for api',
            $text,
        );
        $this->assertStringNotContainsString('user: [signal:', $text);
    }

    public function testAppendStampsFractionalLandedAt(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $clock = new MockClock(1_700_000_000.25);
        $this->container->replace(ClockInterface::class, $clock);

        $this->threadStore()->create(Thread::of('chat-1', '@threads/chat-1'));
        $threads = $this->threadsImpl();
        $thread = new Thread('chat-1', [], [], '@threads/chat-1');
        $c_c13 = Completion::of(UserMessage::of('hello'), new AssistantMessage('hi'));
        $c_c13->completeId = 'c1';
        $c_c13->step = 1;

        $this->threadAppend($thread, $c_c13);
        $clock->advance(0.125);
        $c_c23 = Completion::of(UserMessage::of('again'), new AssistantMessage('ok'));
        $c_c23->completeId = 'c2';
        $c_c23->step = 2;
        $this->threadAppend($thread, $c_c23);

        $this->assertSame(1_700_000_000.25, $thread->turns[0]->completions[0]->landedAt);
        $this->assertSame(1_700_000_000.375, $thread->turns[0]->completions[1]->landedAt);
    }

    public function testSaveTurnDoesNotDispatchCompletionAppended(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $this->threadStore()->create(Thread::of('chat-1', '@threads/chat-1'));
        $thread = new Thread('chat-1', [], [], '@threads/chat-1');
        $completion = Completion::of(UserMessage::of('hello'), new AssistantMessage('hi'));

        $this->threadAppend($thread, $completion);

        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);
        $this->assertSame([], $dispatcher->getEventsByClass(CompletionAppended::class));
    }

    public function testCommitOpenTurnEmitsTurnCommittedWithFoldedCost(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $this->threadStore()->create(Thread::of('chat-1', '@threads/chat-1'));
        $threads = $this->threadsImpl();
        $thread = new Thread('chat-1', [], [], '@threads/chat-1');

        $llm = Completion::of(UserMessage::of('hello'), new AssistantMessage('hi'));
        $llm->usage = new TokenUsage(100, 10);
        $llm->timing = new LlmTiming(wall: 0.5);
        $this->threadAppend($thread, $llm);
        $this->threadCommit($thread);

        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);
        $events = $dispatcher->getEventsByClass(TurnCommitted::class);
        $this->assertCount(1, $events);

        $payload = $events[0]->jsonSerialize();
        $this->assertSame('chat-1', $payload['thread_id']);
        $this->assertSame('completed', $payload['status']);
        $this->assertSame(1, $payload['steps']);
        $this->assertSame(1, $payload['totals']->llmCalls);
        $this->assertSame(100, $payload['totals']->usage?->input);
    }

    public function testCommitOpenTurnMarksAbortedTurn(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $this->threadStore()->create(Thread::of('chat-1', '@threads/chat-1'));
        $threads = $this->threadsImpl();
        $thread = new Thread('chat-1', [], [], '@threads/chat-1');

        $this->threadAppend($thread, Completion::of(UserMessage::of('hello')));
        $this->threadCommit($thread, TurnStatus::Aborted);

        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);
        $events = $dispatcher->getEventsByClass(TurnCommitted::class);

        $this->assertCount(1, $events);
        $this->assertSame('aborted', $events[0]->jsonSerialize()['status']);
    }

    public function testCommitOpenTurnMarksCancelledTurn(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $this->threadStore()->create(Thread::of('chat-1', '@threads/chat-1'));
        $threads = $this->threadsImpl();
        $thread = new Thread('chat-1', [], [], '@threads/chat-1');

        $this->threadAppend($thread, Completion::of(UserMessage::of('hello')));
        $this->threadCommit($thread, TurnStatus::Cancelled);

        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);
        $events = $dispatcher->getEventsByClass(TurnCommitted::class);

        $this->assertCount(1, $events);
        $this->assertSame('cancelled', $events[0]->jsonSerialize()['status']);
    }

    public function testTurnFileExistsOnlyWhileOneTurnIsUnsealed(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $this->threadStore()->create(Thread::of('chat-1', '@threads/chat-1'));
        $threads = $this->threadsImpl();
        $thread = new Thread('chat-1', [], [], '@threads/chat-1');
        $turnFile = $this->threadLeafDir('chat-1') . '/' . Threads::TURN_FILE;

        $this->threadAppend($thread, Completion::of(UserMessage::of('hello')));
        $this->assertFileExists($turnFile);
        $this->assertSame('running', Json::parse(file_get_contents($turnFile))['status']);

        $this->threadCommit($thread);
        $this->assertFileDoesNotExist($turnFile);

        // A suspended turn is still unsealed, so the checkpoint stays.
        $this->threadAppend($thread, Completion::of(UserMessage::of('again')));
        $open = $thread->lastTurn();
        $open->interrupt = new RetryInterrupt();
        $open->status = TurnStatus::Suspended;
        $conversation = $this->threadLeafDir('chat-1') . '/conversation.jsonl';
        $sealed = file_get_contents($conversation);
        $threads->saveTurn($thread);
        $this->assertSame($sealed, file_get_contents($conversation));
        $this->assertFileExists($turnFile);
        $this->assertSame('suspended', Json::parse(file_get_contents($turnFile))['status']);
    }

    /**
     * Crash window: `commit()` appends the sealed turn, then dies before deleting `turn.json`.
     * Both copies are the same turn, so `load()` must not replay the checkpoint as a second turn.
     */
    public function testLoadDropsCheckpointAlreadySealedInConversation(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $this->threadStore()->create(Thread::of('chat-1', '@threads/chat-1'));
        $threads = $this->threadsImpl();
        $thread = new Thread('chat-1', [], [], '@threads/chat-1');
        $this->threadAppend($thread, Completion::of(UserMessage::of('hello')));
        $this->threadCommit($thread);
        $sealed = $thread->lastTurn();

        // Re-create the stale checkpoint commit() would have left behind on a crash.
        $this->container->get(FilesystemInterface::class)
            ->write($this->threadLeafDir('chat-1') . '/' . Threads::TURN_FILE, Json::stringify($sealed) . "\n");

        $turns = $this->threadLoad('chat-1')->turns;
        $this->assertCount(1, $turns);
        $this->assertSame($sealed->turnId, $turns[0]->turnId);
    }

    public function testAppendDoesNotOverwriteStateFile(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $this->threadStore()->create(Thread::of('chat-1', '@threads/chat-1'));
        $threads = $this->threadsImpl();
        $thread = new Thread('chat-1', [], ['cursor' => 1], '@threads/chat-1');

        $this->threadAppend($thread, Completion::of(UserMessage::of('hello'), new AssistantMessage('hi')));

        $dir = $this->threadLeafDir('chat-1');
        $filesystem = $this->container->get(FilesystemInterface::class);

        $this->assertTrue($filesystem->exists($dir . '/state.json'));
        $this->assertSame([], Json::parse($filesystem->read($dir . '/state.json')));
        $this->assertSame([], $this->threadLoad('chat-1')->state);
        $this->assertSame(['cursor' => 1], $thread->state);
    }

    /**
     * `saveTurn()` only writes the unsealed tip. Sealed turns stay in `conversation.jsonl`,
     * so an in-memory thread that never loaded them cannot truncate the file.
     */
    public function testSaveTurnDoesNotRewriteSealedConversation(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $this->threadStore()->create(Thread::of('chat-1', '@threads/chat-1'));
        $threads = $this->threadsImpl();
        $filesystem = $this->container->get(FilesystemInterface::class);
        $conversation = $this->threadLeafDir('chat-1') . '/conversation.jsonl';
        $filesystem->appendJsonl($conversation, Turn::of('t-sealed', [
            Completion::of(UserMessage::of('sealed'), new AssistantMessage('kept')),
        ], TurnStatus::Completed));

        // Deliberately unloaded: `turns` is empty even though the leaf holds a sealed turn.
        $thread = new Thread('chat-1', [], [], '@threads/chat-1');
        $this->threadAppend($thread, Completion::of(UserMessage::of('next'), new AssistantMessage('ok')));

        $lines = array_values(array_filter(
            Strings::rawLines($filesystem->read($conversation)),
            static fn (string $line): bool => trim($line) !== '',
        ));
        $this->assertCount(1, $lines);
        $this->assertSame('t-sealed', Json::parse($lines[0])['turnId']);
        $this->assertFileExists($this->threadLeafDir('chat-1') . '/' . Threads::TURN_FILE);
    }

    public function testAppendWritesJsonlForSubagentThread(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $threads = $this->threadsImpl();
        $threadId = 'host-1.t10001';
        $thread = Thread::of($threadId, $this->threadDir($threadId));
        $threads->create($thread);
        $dir = $this->threadLeafDir($threadId);

        $this->threadAppend($thread, Completion::of(UserMessage::of('delegate'), new AssistantMessage('ok')));
        $this->threadCommit($thread);

        $filesystem = $this->container->get(FilesystemInterface::class);
        $this->assertTrue($filesystem->exists($dir . '/transcript.jsonl'));
        $this->assertTrue($filesystem->exists($dir . '/conversation.jsonl'));
        $this->assertSame([], $this->transcriptCompletions('host-1'));

        $completions = $this->transcriptCompletions($threadId);
        $this->assertCount(1, $completions);
        $this->assertSame('delegate', $completions[0]->input->wire()[0]['text']);
    }

    public function testTranscriptKeepsToolSteps(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $this->threadStore()->create(Thread::of('chat-1', '@threads/chat-1'));
        $threads = $this->threadsImpl();
        $thread = new Thread('chat-1', [], [], '@threads/chat-1');
        $c_c12 = Completion::of(UserMessage::of('run ls'), new AssistantMessage('', [new ToolCall('call-1', 'bash', ['command' => 'ls'])]));
        $c_c12->completeId = 'c1';
        $c_c12->step = 1;

        $this->threadAppend($thread, $c_c12);
        $c_c22 = Completion::of(ToolMessage::of([new ToolResult('{"stdout":"main.go"}', 'call-1', 'bash')]));
        $c_c22->completeId = 'c2';
        $c_c22->step = 2;
        $this->threadAppend($thread, $c_c22);
        $this->threadCommit($thread);

        $completions = $this->transcriptCompletions('chat-1');
        $this->assertCount(2, $completions);
        $this->assertSame('run ls', $completions[0]->input->wire()[0]['text']);
        $this->assertInstanceOf(ToolMessage::class, $completions[1]->input);
        $this->assertSame('bash', $completions[1]->input->results[0]->name);
    }

    public function testForkCopiesConversation(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $threads = $this->threadsImpl();
        $threads->create(Thread::of('chat-src', '@threads/chat-src'));
        $threads->replaceConversation(new Thread('chat-src', [
            Turn::of('t1', [
                Completion::of(UserMessage::of('hello'), new AssistantMessage('hi')),
            ], TurnStatus::Completed),
        ], [], '@threads/chat-src'));

        $copy = $threads->fork(Thread::of('chat-src', '@threads/chat-src'));

        $filesystem = $this->container->get(FilesystemInterface::class);
        $forkDir = $this->threadLeafDir($copy->id);
        $srcDir = $this->threadLeafDir('chat-src');
        $lines = array_values(array_filter(
            Strings::rawLines($filesystem->read($forkDir . '/conversation.jsonl')),
            static fn (string $line): bool => trim($line) !== '',
        ));
        $this->assertCount(1, $lines);
        $this->assertSame('hello', Json::parse($lines[0])['completions'][0]['input']['parts'][0]['text']);

        $sourceLines = array_values(array_filter(
            Strings::rawLines($filesystem->read($srcDir . '/conversation.jsonl')),
            static fn (string $line): bool => trim($line) !== '',
        ));
        $this->assertSame($sourceLines, $lines);
    }

    /** A memory-only thread forks as a deep copy under a new id; a stored thread also copies its leaf. */
    public function testForkMemoryThreadCopiesTurnsInPlace(): void
    {
        $threads = $this->threadsImpl();
        $thread = Thread::of('memory-1');
        $thread->state = ['cursor' => 7];
        $this->threadAppend($thread, Completion::of(UserMessage::of('hello'), new AssistantMessage('hi')));
        $this->threadCommit($thread);

        $copy = $threads->fork($thread);

        $this->assertNotSame('memory-1', $copy->id);
        $this->assertNull($copy->datadir);
        $this->assertSame(['cursor' => 7], $copy->state);
        $this->assertCount(1, $copy->turns);
        $this->assertSame('hello', $copy->firstCompletion()->input->wire()[0]['text']);
        // Deep copy: mutating the branch must not touch the source.
        $this->assertNotSame($thread->turns[0], $copy->turns[0]);
    }

    public function testForkReturnsDistinctThreadId(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $threads = $this->threadsImpl();
        $threads->create(Thread::of('chat-src', '@threads/chat-src'));
        $threads->saveState(new Thread('chat-src', state: ['expert' => 'general'], datadir: '@threads/chat-src'));

        $copy = $threads->fork(Thread::of('chat-src', '@threads/chat-src'));

        $this->assertNotSame('chat-src', $copy->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $copy->id,
        );
        $this->assertTrue(
            $this->container->get(FilesystemInterface::class)->isDir($this->threadLeafDir($copy->id)),
        );
    }

    public function testSaveAndLoadState(): void
    {
        $root = sys_get_temp_dir() . '/switon-thread-state-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $threads = $this->threadsImpl();
        $thread = Thread::of('chat-1', '@threads/chat-1');
        $threads->create($thread);
        $threads->saveState(new Thread('chat-1', state: [
            'expert' => 'general',
            'cursor' => 3,
        ], datadir: '@threads/chat-1'));

        $loaded = Thread::of('chat-1', '@threads/chat-1');
        $threads->load($loaded);
        $state = $loaded->state;
        $this->assertSame('general', $state['expert']);
        $this->assertSame(3, $state['cursor']);
    }

    public function testForkCopiesStateFile(): void
    {
        $root = sys_get_temp_dir() . '/switon-thread-state-fork-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $threads = $this->threadsImpl();
        $threads->create(Thread::of('chat-src', '@threads/chat-src'));
        $threads->saveState(new Thread('chat-src', state: [
            'expert' => 'plan',
        ], datadir: '@threads/chat-src'));
        $copy = $threads->fork(Thread::of('chat-src', '@threads/chat-src'));
        $threads->load($copy);

        $this->assertSame('plan', $copy->state['expert']);
    }

    public function testForkCopiesWholeDirectoryTree(): void
    {
        $root = sys_get_temp_dir() . '/switon-thread-tree-fork-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $threads = $this->threadsImpl();
        $threads->create(Thread::of('chat-src', '@threads/chat-src'));
        $filesystem = $this->container->get(FilesystemInterface::class);
        $srcDir = $this->threadLeafDir('chat-src');
        $filesystem->write($srcDir . '/app.json', "{\"title\":\"keep-me\",\"project_id\":\"demo\"}\n");
        $filesystem->write($srcDir . '/attachments/note.txt', "hello\n");
        $filesystem->mkdir($srcDir . '/subagents/t10001');
        $filesystem->write($srcDir . '/subagents/t10001/transcript.jsonl', "{\"ok\":true}\n");

        $copy = $threads->fork(Thread::of('chat-src', '@threads/chat-src'));
        $forkDir = $this->threadLeafDir($copy->id);

        $this->assertSame(
            "{\"title\":\"keep-me\",\"project_id\":\"demo\"}\n",
            $filesystem->read($forkDir . '/app.json'),
        );
        $this->assertSame("hello\n", $filesystem->read($forkDir . '/attachments/note.txt'));
        $this->assertSame(
            "{\"ok\":true}\n",
            $filesystem->read($forkDir . '/subagents/t10001/transcript.jsonl'),
        );
    }

    public function testForkCopiesFoldedMessageInConversation(): void
    {
        $root = sys_get_temp_dir() . '/switon-thread-summary-fork-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $threads = $this->threadsImpl();
        $threads->create(Thread::of('chat-src', '@threads/chat-src'));
        $threads->replaceConversation(new Thread('chat-src', [
            Turn::of('t-sum', [
                Completion::of((new ExcerptMessage([['role' => 'user', 'text' => 'Folded history.']], true))),
            ], TurnStatus::Completed),
            Turn::of('t1', [
                Completion::of(UserMessage::of('hello'), new AssistantMessage('hi')),
            ], TurnStatus::Completed),
        ], [], '@threads/chat-src'));
        $copy = $threads->fork(Thread::of('chat-src', '@threads/chat-src'));
        $threads->load($copy);
        $this->assertInstanceOf(FoldedMessage::class, $this->flattenWireMessages($copy->completions())[0]);
        // Rows survive the round trip; `content` is composed from them, never stored.
        $this->assertSame(
            ExcerptMessage::HEADER . "\nuser: Folded history.",
            $this->flattenWireMessages($copy->completions())[0]->text(),
        );
    }


    public function testCommitRefreshTurnTotalsSumsLlmUsageAndWall(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $this->threadStore()->create(Thread::of('chat-rollup-llm', '@threads/chat-rollup-llm'));
        $threads = $this->threadsImpl();
        $thread = new Thread('chat-rollup-llm', [], [], '@threads/chat-rollup-llm');

        $this->threadAppend($thread, $this->llmStep('a1', new TokenUsage(10, 20), 1.5));
        $this->threadAppend($thread, $this->llmStep('a2', new TokenUsage(5, 5), 0.5));

        $this->threadCommit($thread);
        $turn = $thread->lastTurn();

        $this->assertSame(2, $turn->totals->llmCalls);
        $this->assertSame(0, $turn->totals->toolCalls);
        $this->assertSame(15, $turn->totals->usage?->input);
        $this->assertSame(25, $turn->totals->usage?->output);
        $this->assertSame(40, $turn->totals->usage?->total());
        $this->assertSame(2.0, $turn->totals->llmWall);
    }

    public function testCommitRefreshTurnTotalsCountsToolsAndMergesNestedTurn(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $this->threadStore()->create(Thread::of('chat-rollup-tools', '@threads/chat-rollup-tools'));
        $thread = new Thread('chat-rollup-tools', state: ['profileId' => 'explore'], datadir: '@threads/chat-rollup-tools');

        $assistant = new AssistantMessage('calling tools', [
            new ToolCall('c1', 'read_file'),
            new ToolCall('c2', 'agent_explore'),
        ]);
        $llm = Completion::of(UserMessage::of('go'), $assistant);
        $llm->completeId = 'c-llm';
        $llm->usage = new TokenUsage(1, 2);
        $llm->timing = LlmTiming::of(1.0);

        $nested = Turn::of('child', status: TurnStatus::Completed);
        $nested->totals->llmCalls = 2;
        $nested->totals->toolCalls = 4;
        $nested->totals->usage = new TokenUsage(8, 12);
        $nested->totals->llmWall = 3.25;

        $tools = Completion::of(ToolMessage::of([
            new ToolResult('file', 'c1', 'read_file'),
            new AsToolResult(
                new AsToolCall(id: 'c2', name: 'agent_explore', threadId: 't1', agent: 'explore', model: 'main'),
                $nested,
                'explored',
                'c2',
                'agent_explore',
            ),
        ]));
        $tools->completeId = 'c-tools';

        $this->threadAppend($thread, $llm);
        $this->threadAppend($thread, $tools);
        $this->threadAppend($thread, $this->llmStep('c-final', new TokenUsage(1, 1), 0.25));

        $this->threadCommit($thread);
        $turn = $thread->lastTurn();

        $this->assertSame(4, $turn->totals->llmCalls);
        $this->assertSame(5, $turn->totals->toolCalls);
        $this->assertSame(10, $turn->totals->usage?->input);
        $this->assertSame(15, $turn->totals->usage?->output);
        $this->assertSame(25, $turn->totals->usage?->total());
        $this->assertSame(4.5, $turn->totals->llmWall);

        // totals.json is this agent only — nested as-tool stays on the child datadir.
        $totals = $this->persistedTotals('@threads/chat-rollup-tools');
        $this->assertSame(2, $totals->llmCalls);
        $this->assertSame(1, $totals->toolCalls);
        $this->assertSame(2, $totals->usage?->input);
        $this->assertSame(3, $totals->usage?->output);
        $this->assertSame(1.25, $totals->llmWall);
        $this->assertGreaterThan(0, $totals->updatedAt);
    }

    public function testCommitBumpsTotalsAcrossTurnsWithoutNested(): void
    {
        $root = sys_get_temp_dir() . '/switon-agent-' . bin2hex(random_bytes(4));
        $this->bindThreadStorageRoot($root);

        $this->threadStore()->create(Thread::of('chat-totals', '@threads/chat-totals'));
        $thread = new Thread('chat-totals', state: ['profileId' => 'general.chat'], datadir: '@threads/chat-totals');

        $this->threadAppend($thread, $this->llmStep('a1', new TokenUsage(10, 20), 1.0));
        $this->threadCommit($thread);

        $this->threadAppend($thread, $this->llmStep('a2', new TokenUsage(5, 5), 0.5));
        $this->threadCommit($thread);

        $totals = $this->persistedTotals('@threads/chat-totals');
        $this->assertSame(2, $totals->llmCalls);
        $this->assertSame(0, $totals->toolCalls);
        $this->assertSame(15, $totals->usage?->input);
        $this->assertSame(25, $totals->usage?->output);
        $this->assertSame(1.5, $totals->llmWall);

        $empty = $this->persistedTotals('@threads/missing-totals');
        $this->assertNull($empty->updatedAt);
        $this->assertNull($empty->usage);
        $this->assertSame(0, $empty->llmCalls);
    }

    protected function persistedTotals(string $datadir): UsageTotals
    {
        $decoded = $this->container->get(FilesystemInterface::class)
            ->readJson(rtrim($datadir, '/') . '/' . 'totals.json');

        return is_array($decoded) ? UsageTotals::decode($decoded) : new UsageTotals();
    }

    protected function llmStep(string $completeId, TokenUsage $usage, float $wall): Completion
    {
        $completion = Completion::of(UserMessage::of('q'), new AssistantMessage('a'));
        $completion->completeId = $completeId;
        $completion->usage = $usage;
        $completion->timing = LlmTiming::of($wall);

        return $completion;
    }

    /** @return list<Completion> */
    protected function transcriptCompletions(string $threadId): array
    {
        $completions = [];
        $dir = $this->threadDir($threadId);
        $fs = $this->container->get(FilesystemInterface::class);
        if ($fs->exists($dir . '/transcript.jsonl')) {
            foreach ($fs->readJsonl($dir . '/transcript.jsonl') as $row) {
                foreach (Turn::decode($row)->completions as $completion) {
                    $completions[] = $completion;
                }
            }
        }

        return $completions;
    }
}
