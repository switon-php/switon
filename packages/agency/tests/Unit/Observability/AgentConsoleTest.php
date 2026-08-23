<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit\Observability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Switon\Agency\Agent;
use Switon\Agency\ProfilesInterface;
use Switon\Ai\TurnStatus;
use Switon\Core\Json;
use Switon\Agency\Thread\TaskFinishSignal;
use Switon\Ai\Event\DeliveryStarted;
use Switon\Agency\Event\StepRequesting;
use Switon\Ai\Event\StepResponded;
use Switon\Tooling\Event\ToolFinished;
use Switon\Tooling\Event\ToolStarted;
use Switon\Agency\Observability\AgentConsole;
use Switon\Agency\Observability\AgentConsoleInterface;
use Switon\Tooling\ToolsInterface;
use Switon\Agency\Tests\TestCase;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Thread;
use Switon\Llm\Event\CompletionRequesting;
use Switon\Llm\Event\CompletionResponded;
use Switon\Llm\Event\CompletionDelta;
use Switon\Ai\LlmInterface;
use Switon\Ai\StreamDelta;
use Switon\Ai\TokenUsage;
use Switon\Ai\Turn;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Ai\ToolTiming;
use Switon\Ai\Message\UserMessage;
use Switon\Testing\Mock\MockConsole;
use ReflectionMethod;
use Switon\Agency\Event\InstructFail;
use Switon\Agency\Event\InstructSuccess;
use Switon\Core\ConsoleInterface;
use Switon\Ai\ToolCallDelta;
use Stringable;

#[CoversClass(AgentConsole::class)]
#[Group('coroutine')]
class AgentConsoleTest extends TestCase
{
    public function testStartRegistersListenerOnce(): void
    {
        $console = $this->container->get(AgentConsoleInterface::class);

        $console->start();
        $console->start();

        $listeners = $this->container->get(ListenerProviderInterface::class)->getListeners();
        $this->assertArrayHasKey(ToolStarted::class, $listeners);
        $this->assertCount(1, $listeners[ToolStarted::class]);
    }

    public function testBootstrapGetThenCommandGetSharesOneConsole(): void
    {
        $bootstrap = $this->container->get(AgentConsoleInterface::class);
        $bootstrap->start();

        $command = $this->container->get(AgentConsoleInterface::class);
        $command->start();

        $listeners = $this->container->get(ListenerProviderInterface::class)->getListeners();
        $this->assertSame($bootstrap, $command);
        $this->assertCount(1, $listeners[ToolStarted::class]);
    }

    public function testStreamsToolArgumentsAndAssistantLines(): void
    {
        $console = $this->make(AgentConsole::class);

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['echo']),
            ]);
        $call = new ToolCall('call_1', 'read', ['path' => '/tmp/demo.php', '.caption' => 'Read demo.php']);

        $console->onDeliveryStarted($this->deliveryStartedEvent(
            $agent,
            UserMessage::of('Read /tmp/demo.php'),
        ));
        $c_chatcmpl_1 = Completion::of(UserMessage::of(''), new AssistantMessage("<thinking>\nHidden.\n</thinking>\nDone.", [$call]));
        $c_chatcmpl_1->completeId = 'chatcmpl-1';
        $c_chatcmpl_1->finishReason = 'tool_calls';
        $c_chatcmpl_1->usage = new TokenUsage();
        $console->onStepResponded(new StepResponded(
            $agent,
            $c_chatcmpl_1,
        ));
        $console->onToolStarted(new ToolStarted($this->container->get(ToolsInterface::class), $call, $this->toolRuntimeHost($agent, $call)));
        $console->onToolFinished(new ToolFinished(
            $this->container->get(ToolsInterface::class),
            $call, $this->toolRuntimeHost($agent, $call),
            ToolResult::of($call, 'ok', new ToolTiming(0.0, 0.0125)),
        ));

        $output = implode("\n", $this->container->get(MockConsole::class)->getOutput());
        $this->assertMatchesRegularExpression('/🧠 \d{2}:\d{2}:\d{2} Done\./u', $output);
        $this->assertStringContainsString('read', $output);
        $this->assertStringNotContainsString("Done.\n  read", $output);
        $this->assertMatchesRegularExpression('/🔧 \d{2}:\d{2}:\d{2} Read demo\.php/u', $output);
        $this->assertDoesNotMatchRegularExpression('/🔧[^\n]*🧠/u', $output);
        $this->assertStringNotContainsString('running', $output);
        $this->assertSame(1, substr_count($output, '🔧'));
        $this->assertStringContainsString('Read demo.php', $output);
        $this->assertStringContainsString('Read /tmp/demo.php', $output);
        $this->assertStringContainsString('👤', $output);
        $this->assertMatchesRegularExpression('/👤 \d{2}:\d{2}:\d{2} Read \/tmp\/demo\.php/u', $output);
        $this->assertStringContainsString('read:', $output);
        $this->assertStringContainsString('/tmp/demo.php', $output);
        $this->assertStringContainsString('⎿ ok 2 B 13ms', $output);
        $this->assertStringNotContainsString('.caption', $output);
        $this->assertStringContainsString('Done.', $output);
        $this->assertStringContainsString('🧠', $output);
        $this->assertStringNotContainsString('Hidden.', $output);
    }

    public function testAssistantDeltaPreservesInterChunkSpaces(): void
    {
        $console = $this->make(AgentConsole::class);
        $method = new ReflectionMethod(AgentConsole::class, 'assistantDelta');
        $text = '';
        foreach (['Done. ', 'Timer-coordinator ', 'sub-agent ', 'created and '] as $delta) {
            $text .= $method->invoke($console, $delta);
        }

        $this->assertSame('Done. Timer-coordinator sub-agent created and ', $text);

        $assistantText = new ReflectionMethod(AgentConsole::class, 'assistantText');
        $glued = '';
        foreach (['Done. ', 'Timer-coordinator ', 'sub-agent ', 'created and '] as $delta) {
            $glued .= $assistantText->invoke($console, $delta);
        }
        $this->assertSame('Done.Timer-coordinatorsub-agentcreated and', $glued);
    }

    public function testCompletionDeltaPreservesSpacesInInlineOutput(): void
    {
        $capturing = new class () extends MockConsole {
            public string $inline = '';

            public function write(string|Stringable $message, array $context = [], int $options = 0): void
            {
                $this->inline .= (string) $message;
            }

            public function writeLn(string|Stringable $message = '', array $context = [], int $options = 0): void
            {
                parent::writeLn($this->inline . (string) $message);
                $this->inline = '';
            }
        };
        $this->container->set(ConsoleInterface::class, $capturing);

        $console = $this->make(AgentConsole::class);
        $host = $this->make(Agent::class);
        $thread = $host->thread();
        $completion = Completion::of(UserMessage::of('hi'));
        $llm = $this->container->get(LlmInterface::class);

        $console->onStepRequesting(new StepRequesting($host, $completion, Charter::of()));
        foreach (['Done. ', 'Timer-coordinator ', 'sub-agent '] as $content) {
            $streamDelta = new StreamDelta(content: $content);
            $console->onCompletionDelta(new CompletionDelta(
                $llm,
                $thread,
                $streamDelta,
                $completion,
                Charter::of(),
            ));
        }

        $this->assertStringContainsString('Done. Timer-coordinator sub-agent ', $capturing->inline);
    }

    public function testStepRespondedFallsBackWhenChunksWereEmpty(): void
    {
        $console = $this->make(AgentConsole::class);
        $host = $this->make(Agent::class);
        $thread = $host->thread();
        $completion = Completion::of(UserMessage::of('hi'));

        $console->onStepRequesting(new StepRequesting($host, $completion, Charter::of()));
        $streamDelta = new StreamDelta(toolCallDeltas: [new ToolCallDelta('c1', 'task_deliver', '{}')]);
        $console->onCompletionDelta(new CompletionDelta(
            $this->container->get(LlmInterface::class),
            $thread,
            $streamDelta,
            $completion,
            Charter::of(),
        ));
        $c_chatcmpl_stream = Completion::of(UserMessage::of(''), new AssistantMessage('Done. Timer leaf ready.', [new ToolCall('c1', 'task_deliver', [])]));
        $c_chatcmpl_stream->completeId = 'chatcmpl-stream';
        $c_chatcmpl_stream->finishReason = 'tool_calls';
        $c_chatcmpl_stream->usage = new TokenUsage();
        $console->onStepResponded(new StepResponded(
            $host,
            $c_chatcmpl_stream,
        ));

        $output = implode("\n", $this->container->get(MockConsole::class)->getOutput());
        $this->assertStringContainsString('Done. Timer leaf ready.', $output);
    }

    public function testDelegateRunShowsNestedPrefix(): void
    {
        $console = $this->make(AgentConsole::class);
        $host = $this->make(Agent::class, [
        ]);

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(id: 'timer', tools: ['sleep']),
            'profileId' => 'timer',
            'parent' => $host,
        ]);
        $leaf = $this->leafOf($agent);

        $console->onDeliveryStarted($this->deliveryStartedEvent(
            $agent,
            UserMessage::of('What time is it?'),
        ));
        $sleepCall = new ToolCall('call_1', 'sleep', ['seconds' => 1, '.caption' => 'Wait 1s']);
        $c_chatcmpl_2 = Completion::of(UserMessage::of(''), new AssistantMessage('Current time is 2025-01-01 1:1:1 UTC.', [$sleepCall]));
        $c_chatcmpl_2->completeId = 'chatcmpl-2';
        $c_chatcmpl_2->finishReason = 'tool_calls';
        $c_chatcmpl_2->usage = new TokenUsage();
        $console->onStepResponded(new StepResponded(
            $agent,
            $c_chatcmpl_2,
        ));
        $console->onToolStarted(new ToolStarted($this->container->get(ToolsInterface::class), $sleepCall, $this->toolRuntimeHost($agent, $sleepCall)));
        $console->onToolFinished(new ToolFinished(
            $this->container->get(ToolsInterface::class),
            $sleepCall, $this->toolRuntimeHost($agent, $sleepCall),
            ToolResult::of($sleepCall, 'woke', new ToolTiming(0.0, 1.0)),
        ));

        $output = implode("\n", $this->container->get(MockConsole::class)->getOutput());
        $this->assertStringContainsString('    ↳ ' . $leaf, $output);
        $this->assertStringContainsString($leaf . ' timer', $output);
        $this->assertMatchesRegularExpression('/    👤 \d{2}:\d{2}:\d{2} What time is it\?/u', $output);
        $this->assertStringNotContainsString(' run ', $output);
        $this->assertStringContainsString('Wait 1s', $output);
        $this->assertMatchesRegularExpression('/    🔧 \d{2}:\d{2}:\d{2} Wait 1s/u', $output);
        $this->assertMatchesRegularExpression('/    🧠 \d{2}:\d{2}:\d{2} Current time is 2025-01-01 1:1:1 UTC\./u', $output);
        $this->assertStringContainsString('sleep', $output);
    }

    /**
     * Three-level transcript (host → child → grandchild):
     *
     *     ↳ timer-task timer
     *         ↳ nested timer
     *         🔧 Get UTC time date:{"argv":["-u"]}
     *           ⎿ 2025-01-01 1:1:1 (UTC) …
     *         🧠 2025-01-01 1:1:1 UTC
     */
    public function testGrandchildDelegateTranscriptShowsDepthIndent(): void
    {
        $mock = $this->container->get(MockConsole::class);
        $mock->clearOutput();

        $console = $this->make(AgentConsole::class);
        $host = $this->make(Agent::class, []);
        $child = $this->spawnDelegated($host, 'timer');
        $childLeaf = $this->leafOf($child);
        $grandchild = $this->make(Agent::class, [
            'profile' => $this->testProfile(id: 'timer', tools: ['date']),
            'profileId' => 'timer',
            'parent' => $child,
        ]);
        $grandchildLeaf = $this->leafOf($grandchild);

        $console->onDeliveryStarted($this->deliveryStartedEvent($child, UserMessage::of('delegate')));
        $console->onDeliveryStarted($this->deliveryStartedEvent($grandchild, UserMessage::of('get time')));

        $dateCall = new ToolCall('call_1', 'date', ['argv' => ['-u', '+%Y-%m-%d %H:%M:%S (UTC)'], '.caption' => 'Get UTC time']);
        $c_chatcmpl_3 = Completion::of(UserMessage::of(''), new AssistantMessage('2025-01-01 1:1:1 UTC', [$dateCall]));
        $c_chatcmpl_3->completeId = 'chatcmpl-3';
        $c_chatcmpl_3->finishReason = 'tool_calls';
        $c_chatcmpl_3->usage = new TokenUsage();
        $console->onStepResponded(new StepResponded(
            $grandchild,
            $c_chatcmpl_3,
        ));
        $console->onToolStarted(new ToolStarted($this->container->get(ToolsInterface::class), $dateCall, $this->toolRuntimeHost($grandchild, $dateCall)));
        $console->onToolFinished(new ToolFinished(
            $this->container->get(ToolsInterface::class),
            $dateCall, $this->toolRuntimeHost($grandchild, $dateCall),
            ToolResult::of($dateCall, '{"exit":0,"stdout":"2025-01-01 1:1:1 (UTC)\\n"}', new ToolTiming(0.0, 0.061)),
        ));

        $mock = $this->container->get(MockConsole::class);
        $lines = $mock->getOutput();

        $this->assertSame(1, $child->depth());
        $this->assertSame(2, $grandchild->depth());
        $output = implode("\n", $lines);
        $this->assertStringContainsString('    ↳ ' . $childLeaf, $output, "lines:\n" . $output);
        $this->assertStringContainsString($childLeaf . ' timer', $output);
        $this->assertStringContainsString('        ↳ ' . $grandchildLeaf, $output);
        $this->assertStringContainsString($grandchildLeaf . ' timer', $output);
        $this->assertMatchesRegularExpression('/        🧠 \d{2}:\d{2}:\d{2} 2025-01-01 1:1:1 UTC/u', $output);
        $this->assertMatchesRegularExpression('/        🔧 \d{2}:\d{2}:\d{2} Get UTC time date:/u', $output);
        // Tool payload is expanded for reading: JSON keys become `key: value` lines.
        $this->assertStringContainsString('⎿ exit: 0', $output);
        $this->assertStringContainsString('2025-01-01 1:1:1 (UTC)', $output);
        $this->assertStringNotContainsString('            ↳', $output);
        $this->assertMatchesRegularExpression('/    👤 \d{2}:\d{2}:\d{2} delegate/u', $output);
        $this->assertMatchesRegularExpression('/        👤 \d{2}:\d{2}:\d{2} get time/u', $output);
    }

    public function testDelegateHeaderIndentScalesToDepthFive(): void
    {
        $mock = $this->container->get(MockConsole::class);
        $console = $this->make(AgentConsole::class);
        $host = $this->make(Agent::class, [
        ]);
        $parent = $host;

        for ($depth = 1; $depth <= 5; $depth++) {
            $mock->clearOutput();

            $agent = $this->make(Agent::class, [
                'profile' => $this->testProfile(id: 'timer', tools: ['sleep']),
                'profileId' => 'timer',
                'parent' => $parent,
            ]);
            $leaf = $this->leafOf($agent);

            $console->onDeliveryStarted($this->deliveryStartedEvent($agent, UserMessage::of('')));

            $line = implode("\n", $mock->getOutput());
            $this->assertStringContainsString(str_repeat('    ', $depth) . '↳ ' . $leaf, $line);
            $this->assertStringContainsString('timer', $line);
            $parent = $agent;
        }
    }

    public function testSubDeliveryStartedShowsUserInputLine(): void
    {
        $mock = $this->container->get(MockConsole::class);
        $mock->clearOutput();

        $console = $this->make(AgentConsole::class);
        $host = $this->make(Agent::class, [
        ]);
        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(
                id: 'timer',
                tools: ['sleep'],
                prompt: "## Sub-agent protocol\nBe quiet.",
            ),
            'profileId' => 'timer',
            'parent' => $host,
        ]);
        $leaf = $this->leafOf($agent);

        $console->onDeliveryStarted($this->deliveryStartedEvent(
            $agent,
            UserMessage::of('hidden user line'),
        ));

        $output = implode("\n", $mock->getOutput());
        $this->assertStringContainsString('    ↳ ' . $leaf, $output);
        $this->assertStringContainsString('👤', $output);
        $this->assertStringContainsString('hidden user line', $output);
    }

    public function testSubAgentSkipsSystemOnLaterRuns(): void
    {
        $mock = $this->container->get(MockConsole::class);
        $mock->clearOutput();

        $console = $this->make(AgentConsole::class);
        $host = $this->make(Agent::class, [
        ]);
        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(id: 'timer', tools: ['sleep']),
            'profileId' => 'timer',
            'parent' => $host,
        ]);
        $leaf = $this->leafOf($agent);

        $agent->thread()->turns[] = Turn::of(
            't',
            completions: [Completion::of(UserMessage::of(''), new AssistantMessage('earlier'))],
            status: TurnStatus::Completed,
        );

        $console->onDeliveryStarted($this->deliveryStartedEvent(
            $agent,
            UserMessage::of('again'),
        ));

        $output = implode("\n", $mock->getOutput());
        $this->assertStringNotContainsString('    ↳ ' . $leaf, $output);
        $this->assertStringNotContainsString('⚙️ system', $output);
        $this->assertStringContainsString('again', $output);
    }

    public function testSystemPromptIsHiddenByDefault(): void
    {
        $console = $this->make(AgentConsole::class);
        $system = "## Alpha\nBody line that must stay hidden";

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['echo'], prompt: $system),
        ]);

        $console->onDeliveryStarted($this->deliveryStartedEvent(
            $agent,
            UserMessage::of('Hi'),
        ));

        $output = implode("\n", $this->container->get(MockConsole::class)->getOutput());

        // Working mode: the system prompt is internal state, not transcript.
        $this->assertStringNotContainsString('system', $output);
        $this->assertStringNotContainsString('## Alpha', $output);
        $this->assertStringNotContainsString('Body line that must stay hidden', $output);
    }

    public function testSystemExpandedShowsPromptBody(): void
    {
        $console = $this->make(AgentConsole::class);
        $console->configure(showPrompt: true);
        $system = "## Alpha\nBody line";

        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['echo'], prompt: $system),
        ]);

        $console->onDeliveryStarted($this->deliveryStartedEvent(
            $agent,
            UserMessage::of('Hi'),
        ));

        $output = implode("\n", $this->container->get(MockConsole::class)->getOutput());
        $this->assertStringContainsString('## Alpha', $output);
        $this->assertStringContainsString('Body line', $output);
    }

    public function testToolResultPrintsFullPayload(): void
    {
        $console = $this->make(AgentConsole::class);
        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['echo']),
        ]);
        $call = new ToolCall('call_1', 'echo', []);

        $console->onToolStarted(new ToolStarted($this->container->get(ToolsInterface::class), $call, $this->toolRuntimeHost($agent, $call)));

        $output = implode("\n", $this->container->get(MockConsole::class)->getOutput());
        $this->assertStringContainsString('echo:{}', $output);

        // The tool layer already caps its own output; the transcript must not truncate again.
        $payload = str_repeat('x', 1100);

        $console->onToolFinished(new ToolFinished(
            $this->container->get(ToolsInterface::class),
            $call, $this->toolRuntimeHost($agent, $call),
            ToolResult::of($call, $payload, new ToolTiming(0.0, 0.001)),
        ));

        $output = implode("\n", $this->container->get(MockConsole::class)->getOutput());
        $this->assertStringContainsString($payload, $output);
    }

    public function testStripEventsAreVisibleInConsole(): void
    {
        $console = $this->make(AgentConsole::class);
        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['echo']),
        ]);
        $call = new ToolCall('call_echo', 'echo', ['value' => 'pong']);

        $console->onToolStarted(new ToolStarted($this->container->get(ToolsInterface::class), $call, $this->toolRuntimeHost($agent, $call)));
        $console->onToolFinished(new ToolFinished(
            $this->container->get(ToolsInterface::class),
            $call, $this->toolRuntimeHost($agent, $call),
            ToolResult::of($call, 'pong', new ToolTiming(0.0, 0.001)),
        ));

        $output = implode("\n", $this->container->get(MockConsole::class)->getOutput());
        $this->assertStringContainsString('echo:', $output);
        $this->assertStringNotContainsString('history rewritten', $output);
    }

    public function testStandaloneChatPlannerShowsPromptUserAndToolCall(): void
    {
        $mock = $this->container->get(MockConsole::class);
        $mock->clearOutput();

        $console = $this->make(AgentConsole::class);
        $llm = $this->container->get(LlmInterface::class);
        $completion = Completion::of(UserMessage::of("TRANSCRIPT TO FOLD\n\nSummarize once."));
        $requesting = new CompletionRequesting(
            $llm,
            new Thread(''),
            $completion,
            Charter::of(prompt: "## Compact\n\nPlanner rules.", toolChoice: 'echo'),
        );

        $console->onCompletionRequesting($requesting);
        $requesting->completion->output = new AssistantMessage('', [
            new ToolCall('call_echo', 'echo', [
                'value' => 'Summary.',
            ]),
        ]);
        $requesting->completion->finishReason = 'tool_calls';
        $requesting->completion->usage = new TokenUsage();
        $console->onCompletionResponded(new CompletionResponded(
            $llm,
            new Thread(''),
            $requesting->completion,
            $requesting->charter,
        ));

        $output = implode("\n", $mock->getOutput());
        $this->assertStringContainsString('TRANSCRIPT TO FOLD', $output);
        $this->assertStringContainsString('echo:', $output);
        $this->assertStringContainsString('Summary.', $output);
    }

    public function testStandaloneChatBackToBackUserMessagesHaveNoBlankGap(): void
    {
        $mock = $this->container->get(MockConsole::class);
        $mock->clearOutput();

        $console = $this->make(AgentConsole::class);
        $llm = $this->container->get(LlmInterface::class);
        $requesting = new CompletionRequesting(
            $llm,
            new Thread(''),
            Completion::of(UserMessage::of("TRANSCRIPT\nassistant: Done.\nCall once.")),
            Charter::of(),
        );

        $console->onCompletionRequesting($requesting);

        $output = implode("\n", $mock->getOutput());
        $this->assertMatchesRegularExpression('/assistant: Done\.\n  Call once\./u', $output);
    }

    public function testStandaloneChatIgnoredWhenConversationHasId(): void
    {
        $mock = $this->container->get(MockConsole::class);
        $mock->clearOutput();

        $console = $this->make(AgentConsole::class);
        $llm = $this->container->get(LlmInterface::class);
        $requesting = new CompletionRequesting(
            $llm,
            new Thread('thread-1'),
            Completion::of(UserMessage::of('Should not print.')),
            Charter::of(prompt: 'Hidden planner prompt.'),
        );

        $console->onCompletionRequesting($requesting);

        $this->assertSame('', trim(implode("\n", $mock->getOutput())));
    }

    public function testTaskSubmitPrintsAtChildToolCall(): void
    {
        $mock = $this->container->get(MockConsole::class);
        $mock->clearOutput();

        $console = $this->make(AgentConsole::class);
        $host = $this->make(Agent::class, []);
        $finder = $this->make(Agent::class, [
            'profileId' => 'lite',
            'parent' => $host,
        ]);
        $leaf = $this->leafOf($finder);

        $call = new ToolCall('call_1', 'task_finish', [
            'message' => '/path/NativeToolInterface.php',
            'status' => 'completed',
        ]);

        $console->onToolStarted(new ToolStarted($this->container->get(ToolsInterface::class), $call, $this->toolRuntimeHost($finder, $call)));
        $console->onToolFinished(new ToolFinished(
            $this->container->get(ToolsInterface::class),
            $call, $this->toolRuntimeHost($finder, $call),
            ToolResult::of($call, '{"status":"delivered"}', new ToolTiming()),
        ));

        $output = implode("\n", $mock->getOutput());
        $this->assertStringContainsString('    🔧', $output);
        $this->assertStringContainsString('task_finish:' . $leaf . ' → parent', $output);
        $this->assertStringContainsString('/path/NativeToolInterface.php', $output);
        $this->assertStringNotContainsString('{"status":"delivered"}', $output);
    }

    public function testParentInboxTaskFinishIsSilent(): void
    {
        $mock = $this->container->get(MockConsole::class);
        $mock->clearOutput();

        $console = $this->make(AgentConsole::class);
        $host = $this->make(Agent::class, []);

        $submit = new TaskFinishSignal([
            'thread_id' => 'finder',
            'status' => 'completed',
        ], '/path/NativeToolInterface.php');

        $console->onDeliveryStarted($this->deliveryStartedEvent($host, $submit));

        $this->assertSame([], $mock->getOutput(), 'inbox task events must not reprint after the tool call site');
    }

    public function testTaskDeliverPrintsAtParentToolCall(): void
    {
        $mock = $this->container->get(MockConsole::class);
        $mock->clearOutput();

        $console = $this->make(AgentConsole::class);
        $host = $this->make(Agent::class, []);

        $call = new ToolCall(
            'call_1',
            'task_deliver',
            [
                'agent' => 'lite',
                'message' => 'find the file',
            ],
        );

        $console->onToolStarted(new ToolStarted($this->container->get(ToolsInterface::class), $call, $this->toolRuntimeHost($host, $call)));

        $output = implode("\n", $mock->getOutput());
        $this->assertSame('', trim($output), 'task_deliver prints on ToolFinished, not ToolStarted');

        $console->onToolFinished(new ToolFinished(
            $this->container->get(ToolsInterface::class),
            $call, $this->toolRuntimeHost($host, $call),
            ToolResult::of($call, json_encode([
                'kind' => 'task_deliver',
                'from' => 'parent',
                'to' => 'finder',
                'status' => 'delivered',
            ], JSON_THROW_ON_ERROR), new ToolTiming()),
        ));

        $output = implode("\n", $mock->getOutput());
        $this->assertStringContainsString('🔧', $output);
        $this->assertStringContainsString('task_deliver:parent → finder', $output);
        $this->assertStringContainsString('find the file', $output);
    }

    public function testTaskToolsHideRawJsonDump(): void
    {
        $mock = $this->container->get(MockConsole::class);
        $mock->clearOutput();

        $console = $this->make(AgentConsole::class);
        $host = $this->make(Agent::class, []);
        $finder = $this->make(Agent::class, [
            'profileId' => 'lite',
            'parent' => $host,
        ]);
        $leaf = $this->leafOf($finder);
        $call = new ToolCall('call_1', 'task_finish', [
            'message' => 'ok',
            'status' => 'completed',
        ]);

        $console->onToolStarted(new ToolStarted($this->container->get(ToolsInterface::class), $call, $this->toolRuntimeHost($finder, $call)));
        $console->onToolFinished(new ToolFinished(
            $this->container->get(ToolsInterface::class),
            $call, $this->toolRuntimeHost($finder, $call),
            ToolResult::of($call, '{"status":"delivered"}', new ToolTiming()),
        ));

        $output = implode("\n", $mock->getOutput());
        $this->assertStringContainsString('task_finish:' . $leaf . ' → parent', $output);
        $this->assertStringNotContainsString('{"status":"delivered"}', $output);
        $this->assertStringNotContainsString('Run task_finish', $output);
    }

    public function testStepRespondedMarksFirstToolLineWithBrain(): void
    {
        $mock = $this->container->get(MockConsole::class);
        $mock->clearOutput();

        $console = $this->make(AgentConsole::class);
        $agent = $this->make(Agent::class, [
            'profile' => $this->testProfile(tools: ['grep', 'read']),
            ]);
        $grep = new ToolCall('c1', 'grep', ['pattern' => 'x', '.caption' => 'Grep x']);
        $read = new ToolCall('c2', 'read', ['path' => '/tmp/a.php', '.caption' => 'Read a']);
        $c_chatcmpl_tools = Completion::of(UserMessage::of(''), new AssistantMessage('', [$grep, $read]));
        $c_chatcmpl_tools->completeId = 'chatcmpl-tools';
        $c_chatcmpl_tools->finishReason = 'tool_calls';
        $c_chatcmpl_tools->usage = new TokenUsage();
        $c_chatcmpl_tools->step = 1;

        $console->onStepResponded(new StepResponded(
            $agent,
            $c_chatcmpl_tools,
        ));
        $console->onToolStarted(new ToolStarted($this->container->get(ToolsInterface::class), $grep, $this->toolRuntimeHost($agent, $grep)));
        $console->onToolStarted(new ToolStarted($this->container->get(ToolsInterface::class), $read, $this->toolRuntimeHost($agent, $read)));

        $output = implode("\n", $mock->getOutput());
        $this->assertDoesNotMatchRegularExpression('/🧠 \d{2}:\d{2}:\d{2} grep/u', $output);
        $this->assertMatchesRegularExpression('/🔧 \d{2}:\d{2}:\d{2} 🧠 Grep x grep:/u', $output);
        $this->assertDoesNotMatchRegularExpression('/🔧 \d{2}:\d{2}:\d{2} 🧠 Read a/u', $output);
        $this->assertSame(1, substr_count($output, '🧠'));
    }

    public function testInstructSuccessUsesDistinctGlyph(): void
    {
        $mock = $this->container->get(MockConsole::class);
        $mock->clearOutput();

        $console = $this->make(AgentConsole::class);
        $host = $this->make(Agent::class, []);
        $envelope = [
            'code' => 'ok',
            'data' => ['path' => '/tmp/NativeToolInterface.php'],
        ];
        $call = new ToolCall('call_1', 'response', $envelope);
        $c_chatcmpl_schema = Completion::of(UserMessage::of(''), new AssistantMessage('', [$call]));
        $c_chatcmpl_schema->completeId = 'chatcmpl-schema';
        $c_chatcmpl_schema->finishReason = 'tool_calls';
        $c_chatcmpl_schema->usage = new TokenUsage();
        $c_chatcmpl_schema->step = 1;

        $console->onStepResponded(new StepResponded(
            $host,
            $c_chatcmpl_schema,
        ));
        $console->onInstructSuccess(new InstructSuccess(
            $host,
            $call,
            $envelope,
        ));

        $output = implode("\n", $mock->getOutput());
        $this->assertStringContainsString('response:', $output);
        $this->assertStringContainsString('code:ok', $output);
        $this->assertStringContainsString('/tmp/NativeToolInterface.php', $output);
        $this->assertStringContainsString('💠', $output);
        $this->assertMatchesRegularExpression('/💠 \d{2}:\d{2}:\d{2} 🧠 response:/u', $output);
        $this->assertSame(1, substr_count($output, '🧠'));
    }

    public function testInstructFailUsesDistinctGlyph(): void
    {
        $mock = $this->container->get(MockConsole::class);
        $mock->clearOutput();

        $console = $this->make(AgentConsole::class);
        $host = $this->make(Agent::class, []);
        $envelope = ['code' => 'inconclusive', 'msg' => 'no path'];
        $call = new ToolCall('call_1', 'response', $envelope);

        $console->onInstructFail(new InstructFail(
            $host,
            $call,
            'inconclusive',
            'no path',
        ));

        $output = implode("\n", $mock->getOutput());
        $this->assertStringContainsString('response:', $output);
        $this->assertStringContainsString('code:inconclusive', $output);
        $this->assertStringContainsString('msg:no path', $output);
        $this->assertStringContainsString('⛔', $output);
        $this->assertDoesNotMatchRegularExpression('/inconclusive —/', $output);
    }
}
