<?php

declare(strict_types=1);

namespace Switon\Agency\Observability;

use Switon\Agency\Event\StepRequesting;
use Switon\Agency\Event\InstructFail;
use Switon\Agency\Event\InstructSuccess;
use Switon\Ai\AgentInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Signal\AssistantSignal;
use Switon\Ai\Thread;
use Switon\Ai\Event\StepResponded;
use Switon\Ai\Event\DeliveryStarted;
use Switon\Tooling\Event\ToolFailed;
use Switon\Tooling\Event\ToolFinished;
use Switon\Tooling\Event\ToolStarted;
use Switon\Ai\Message;
use Switon\Ai\Signal;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolTiming;
use Switon\Ai\Message\UserMessage;
use Switon\Console\Colors;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\Core\Json;
use Swoole\Coroutine;
use Switon\Eventing\Attribute\EventListener;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Eventing\ObservabilityProbe;
use Switon\Llm\Event\CompletionDelta;
use Switon\Llm\Event\CompletionRequesting;
use Switon\Llm\Event\CompletionResponded;
use Throwable;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function str_starts_with;
use function trim;

/** Opt-in agent transcript console for CLI debugging. */
class AgentConsole implements AgentConsoleInterface, ObservabilityProbe
{
    protected const string ICON_SYSTEM = '⚙️';
    protected const string ICON_USER = '👤';
    protected const string ICON_TOOL = '🔧';
    protected const string ICON_ASSISTANT = '🧠';
    protected const string ICON_SCHEMA_RESULT = '💠';
    protected const string ICON_SCHEMA_FAIL = '⛔';

    /** Max characters for a tool-call argument line in working mode. */
    protected const int ARGUMENT_PREVIEW_CHARS = 96;

    protected const string SEP = ' ';

    #[Autowired] protected ListenerProviderInterface $listenerProvider;
    #[Autowired] protected ConsoleInterface $console;

    /** Print the full system prompt (hidden by default; it is internal state, not conversation). */
    public bool $showPrompt = false;

    /** Print tool-call arguments as raw JSON instead of a trimmed one-line summary. */
    public bool $rawTools = false;

    /** Set to false by {@see stopToolProgress()}; the animator exits on its next tick. */
    protected bool $progressRunning = false;

    /**
     * Session ids waiting to append `ICON_ASSISTANT` on the next tool / schema line
     * (set from `onStepResponded` when the LLM returned tool calls with no assistant text).
     *
     * @var array<string, true>
     */
    protected array $llmMarkPending = [];
    /** Host streaming steps: thread id => agent (from {@see StepRequesting}). */
    /** @var array<string, AgentInterface> */
    protected array $streamAgentByThread = [];
    /** Thread ids with an open inline assistant stream line. */
    /** @var array<string, true> */
    protected array $streamAssistantOpen = [];
    /** Thread ids that already wrote visible stream body (inline {@see ConsoleInterface::write()}). */
    /** @var array<string, true> */
    protected array $streamAssistantWroteBody = [];

    public function configure(bool $showPrompt = false, bool $rawTools = false): void
    {
        $this->showPrompt = $showPrompt;
        $this->rawTools = $rawTools;
    }

    public function start(): void
    {
        $this->listenerProvider->register($this);
    }

    #[EventListener]
    public function onDeliveryStarted(DeliveryStarted $event): void
    {
        $input = $event->completion->input;
        if ($input instanceof Signal) {
            return;
        }

        $sender = $event->sender;
        $thread = $sender->thread();
        if ($sender->parent() !== null) {
            if (!$this->hasPriorAssistantReply($thread)) {
                $this->writeDelegateStart($sender);
                $system = $this->systemMessage($sender->charter()->prompt);
                if ($system !== null) {
                    $this->writeSystemMessage($system, $this->linePrefix($sender));
                }
            }
            $this->writeUserMessage($sender, $input);

            return;
        }

        if (!$this->hasPriorAssistantReply($thread)) {
            $system = $this->systemMessage($sender->charter()->prompt);
            if ($system !== null) {
                $this->writeSystemMessage($system, $this->linePrefix($sender));
            }
        }

        $this->writeUserMessage($sender, $input);
    }

    #[EventListener]
    public function onToolStarted(ToolStarted $event): void
    {
        if ($this->isTaskTool($event->call->name)) {
            return;
        }

        $agent = $event->agent;
        $args = $this->formatArguments($event->call->arguments);
        $this->console->writeLn(
            $this->linePrefix($agent)
            . $this->toolGlyph()
            . self::SEP
            . $this->lineTimestamp()
            . $this->consumeLlmMark($agent)
            . self::SEP
            . $this->toolLabel($event->call->name, $event->call->arguments)
            . ':' . $this->secondary($args),
        );

        $this->startToolProgress($event->call->name);
    }

    #[EventListener]
    public function onToolFinished(ToolFinished $event): void
    {
        $this->stopToolProgress();
        $content = $this->resultContent($event->result->content);
        $agent = $event->agent;
        if ($this->isTaskTool($event->call->name)) {
            $this->writeTaskToolCall($agent, $event->call->name, $event->call->arguments, $content);

            return;
        }

        // `Tools::call()` always stamps timing; a hand-built result may not.
        $timing = $event->result->timing ?? new ToolTiming();
        $this->writeToolResult(
            $agent,
            $content,
            strlen($content),
            $timing->receive,
            $timing->execute,
        );
    }

    #[EventListener]
    public function onToolFailed(ToolFailed $event): void
    {
        $this->stopToolProgress();
        $agent = $event->agent;
        $this->console->writeLn(
            $this->linePrefix($agent)
            . $this->error('  ⎿ ' . trim($event->message())),
        );
    }

    /**
     * Animate a one-line progress indicator below the tool call while it runs.
     *
     * Only on a TTY: redirected output would fill with carriage returns. The line is redrawn in
     * place with `\r` and erased by {@see stopToolProgress()}, so no scrolling happens.
     */
    protected function startToolProgress(string $toolName): void
    {
        $this->stopToolProgress();

        if (!$this->isInteractiveOutput()) {
            return;
        }

        $frames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        $startedAt = microtime(true);
        $this->progressRunning = true;

        Coroutine::create(function () use ($frames, $startedAt, $toolName): void {
            $index = 0;
            // Re-check after every sleep: stopToolProgress() flips the flag while we are parked.
            while (true) {
                Coroutine::sleep(0.1);
                if (!$this->progressRunning) {
                    return;
                }

                $frame = $frames[$index % count($frames)];
                ++$index;
                $this->console->write(
                    "\r\033[K" . $this->dim(
                        '  ' . $frame . ' ' . $toolName . ' ' . $this->formatElapsedSeconds(microtime(true) - $startedAt),
                    ),
                );
            }
        });
    }

    protected function stopToolProgress(): void
    {
        if (!$this->progressRunning) {
            return;
        }

        // Signal first: Coroutine::cancel() does not interrupt a coroutine parked in sleep().
        $this->progressRunning = false;
        // Erase the animation line so the tool result starts on a clean row.
        $this->console->write("\r\033[K");
    }

    /**
     * True when STDOUT is a terminal, so in-place redraw is safe.
     *
     * Deliberately independent of `NO_COLOR`: colour is a style choice, the progress line is
     * feedback. Redirected output has no cursor to move, so it must stay silent.
     */
    protected function isInteractiveOutput(): bool
    {
        return stream_isatty(STDOUT);
    }

    #[EventListener]
    public function onInstructSuccess(InstructSuccess $event): void
    {
        $this->writeResponseTerminal($event->sender, $event->toolCall, $this->instructSuccessGlyph());
    }

    #[EventListener]
    public function onInstructFail(InstructFail $event): void
    {
        $this->writeResponseTerminal($event->sender, $event->toolCall, $this->instructFailGlyph());
    }

    /** Same argument line as `onToolStarted`; glyph only marks ok vs fail. */
    protected function writeResponseTerminal(AgentInterface $agent, ToolCall $call, string $glyph): void
    {
        $this->console->writeLn(
            $this->linePrefix($agent)
            . $glyph
            . self::SEP
            . $this->lineTimestamp()
            . $this->consumeLlmMark($agent)
            . self::SEP
            . $this->toolLabel($call->name, $call->arguments)
            . ':' . $this->secondary($this->formatArguments($call->arguments)),
        );
    }

    #[EventListener]
    public function onStepRequesting(StepRequesting $event): void
    {
        $this->streamAgentByThread[$event->sender->thread()->id] = $event->sender;
    }

    #[EventListener]
    public function onCompletionDelta(CompletionDelta $event): void
    {
        $threadId = $event->thread->id;
        if ($threadId === '' || !isset($this->streamAgentByThread[$threadId])) {
            return;
        }

        if ($event->streamDelta->toolCallDeltas !== []) {
            if (isset($this->streamAssistantOpen[$threadId])) {
                $this->console->writeLn('');
                unset($this->streamAssistantOpen[$threadId]);
            }

            return;
        }

        $delta = $this->assistantDelta($event->streamDelta->content);
        if ($delta === '') {
            return;
        }

        $agent = $this->streamAgentByThread[$threadId];
        if (!isset($this->streamAssistantOpen[$threadId])) {
            $this->console->writeLn('');
            $this->console->write(
                $this->linePrefix($agent)
                . $this->assistantGlyph()
                . self::SEP
                . $this->lineTimestamp()
                . self::SEP
                . $this->body($delta),
            );
            $this->streamAssistantOpen[$threadId] = true;
            $this->streamAssistantWroteBody[$threadId] = true;

            return;
        }

        $this->console->write($this->body($delta));
        $this->streamAssistantWroteBody[$threadId] = true;
    }

    #[EventListener]
    public function onStepResponded(StepResponded $event): void
    {
        $threadId = $event->sender->thread()->id;
        unset($this->streamAgentByThread[$threadId]);
        $streamed = isset($this->streamAssistantOpen[$threadId]);
        $wroteStreamBody = isset($this->streamAssistantWroteBody[$threadId]);
        if ($streamed) {
            unset($this->streamAssistantOpen[$threadId]);
            $this->console->writeLn('');
        }
        unset($this->streamAssistantWroteBody[$threadId]);

        $output = $event->completion->output;
        if ($output === null || $output instanceof AssistantSignal) {
            return;
        }

        if (!($output instanceof AssistantMessage)) {
            return;
        }

        $text = $this->assistantText($output->content);
        $hasTools = $output->toolCalls !== [];

        if ($text !== '' && !$streamed && !$wroteStreamBody) {
            // Text already marks this LLM step; do not also suffix the first tool line.
            $this->writeAssistantMessages($event->sender, $text, padAfter: !$hasTools);

            return;
        }

        if ($hasTools) {
            $this->llmMarkPending[$event->sender->thread()->id] = true;
        }
    }

    /** Standalone LLM calls ({@see new Thread('')}): compact planner, etc. */
    #[EventListener]
    public function onCompletionRequesting(CompletionRequesting $event): void
    {
        if (!$this->isStandaloneThread($event->thread)) {
            return;
        }

        $system = $this->systemMessage($event->charter->prompt);
        if ($system !== null) {
            $this->writeSystemMessage($system);
        }

        $text = $event->completion->input instanceof UserMessage ? $this->formatUserContent($event->completion->input) : '';
        if ($text === '') {
            return;
        }

        $this->writeRoleMessage(
            '',
            $this->userGlyph(),
            $this->userTone(...),
            $text,
            padBefore: true,
            padAfter: false,
        );
    }

    #[EventListener]
    public function onCompletionResponded(CompletionResponded $event): void
    {
        if (!$this->isStandaloneThread($event->thread)) {
            return;
        }

        $output = $event->completion->output;
        if (!$output instanceof AssistantMessage) {
            return;
        }

        $text = $this->assistantText((string)($output->content ?? ''));
        $hasTools = $output->toolCalls !== [];

        if ($text !== '') {
            $this->writeRoleMessage('', $this->assistantGlyph(), $this->body(...), $text, padAfter: !$hasTools);
        }

        $brainPending = $text === '' && $hasTools;
        foreach ($output->toolCalls as $call) {
            $this->console->writeLn(
                $this->toolGlyph()
                . self::SEP
                . $this->lineTimestamp()
                . ($brainPending ? self::SEP . $this->assistantGlyph() : '')
                . self::SEP
                . $this->toolLabel($call->name, $call->arguments)
                . ':' . $this->secondary($this->formatArguments($call->arguments)),
            );
            $brainPending = false;
        }
    }

    protected function isStandaloneThread(Thread $thread): bool
    {
        return $thread->id === '';
    }

    protected function writeUserMessage(AgentInterface $agent, Message $message): void
    {
        if ($message instanceof Signal) {
            // Outgoing task_* tools already printed at the call site; signal delivery is silent.
            return;
        }

        $text = match (true) {
            $message instanceof UserMessage => $this->formatUserContent($message),
            default => '',
        };
        if ($text === '') {
            return;
        }

        $this->writeRoleMessage($this->linePrefix($agent), $this->userGlyph(), $this->userTone(...), $text);
    }

    protected function writeTaskEventMessage(string $linePrefix, string $text, string $llmMark = ''): void
    {
        $kind = 'task';
        $detail = trim($text);
        if (preg_match('/^(\S+)\s+(.*)$/s', $detail, $matches) === 1) {
            $kind = $matches[1];
            $detail = $matches[2];
        }

        $this->console->writeLn(
            $linePrefix
            . $this->toolGlyph()
            . self::SEP
            . $this->lineTimestamp()
            . $llmMark
            . self::SEP
            . $this->toolName($kind)
            . ':' . $this->secondary($detail),
        );
    }

    /**
     * Compact task_* line at the calling agent (chronological); not the raw JSON tool dump.
     *
     * @param array<string, mixed> $args
     */
    protected function writeTaskToolCall(
        ?AgentInterface $sender,
        string          $name,
        array           $args,
        string          $resultContent = '',
    ): void {
        $result = [];
        if ($resultContent !== '') {
            try {
                $decoded = json_decode($resultContent, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $result = $decoded;
                }
            } catch (Throwable) {
                $result = [];
            }
        }

        $from = is_string($result['thread_id'] ?? null) && trim($result['thread_id']) !== ''
            ? trim($result['thread_id'])
            : (is_string($result['from'] ?? null) && trim($result['from']) !== ''
                ? trim($result['from'])
                : (($sender !== null && $sender->parent() !== null) ? (pathinfo($sender->threadId(), PATHINFO_EXTENSION)) : 'parent'));
        $to = is_string($result['to'] ?? null) && trim($result['to']) !== ''
            ? trim($result['to'])
            : (is_string($args['to'] ?? null) ? trim($args['to']) : '');
        if ($to === '' && $name === 'task_finish') {
            $to = 'parent';
        }

        $body = is_string($args['message'] ?? null) ? $args['message'] : '';

        $header = $name . ' ' . $from . ' → ' . $to;
        $text = $body === '' ? $header : $header . ': ' . $body;

        $this->writeTaskEventMessage(
            $this->linePrefix($sender),
            $text,
            $this->consumeLlmMark($sender),
        );
    }

    protected function isTaskTool(string $name): bool
    {
        return str_starts_with($name, 'task_');
    }

    protected function formatUserContent(UserMessage $message): string
    {
        return trim($message->text() ?? '');
    }

    protected function writeAssistantMessages(AgentInterface $agent, string $text, bool $padAfter = true): void
    {
        $this->writeRoleMessage($this->linePrefix($agent), $this->assistantGlyph(), $this->body(...), $text, padAfter: $padAfter);
    }

    protected function writeToolResult(
        AgentInterface $agent,
        string          $content,
        int             $bytes,
        float           $receiveElapsed,
        float           $executeElapsed,
    ): void {
        $text = trim($content);
        if ($text === '') {
            return;
        }

        // Show what the model received: tool output is JSON-encoded, so `\n` must become real lines.
        $text = $this->expandJsonString($text);
        $lines = array_values(array_filter(
            preg_split('/\R/u', $text) ?: [$text],
            static fn (string $line): bool => trim($line) !== '',
        ));
        if ($lines === []) {
            return;
        }

        $meta = trim($this->toolMeta($bytes, $receiveElapsed, $executeElapsed));
        $last = count($lines) - 1;

        foreach ($lines as $index => $line) {
            $prefix = $index === 0 ? '  ⎿ ' : '    ';
            $suffix = $index === $last && $meta !== '' ? $this->dim(self::SEP . $meta) : '';
            $this->console->writeLn(
                $this->linePrefix($agent) . $this->secondary($prefix . $line) . $suffix,
            );
        }
    }

    protected function writeSystemMessage(string $system, string $prefix = ''): void
    {
        // Working mode: the prompt is internal state, not conversation.
        if (!$this->showPrompt) {
            return;
        }

        $text = trim($system);
        $header = self::ICON_SYSTEM . self::SEP . 'system' . self::SEP . $this->formatBytes(strlen($system));

        $this->console->writeLn($prefix . $this->systemTone($header));
        foreach (preg_split('/\R/u', $text) ?: [$text] as $line) {
            $this->console->writeLn($prefix . $line);
        }
    }

    protected function formatBytes(int $bytes): string
    {
        return $bytes >= 1024 ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B';
    }

    protected function writeRoleMessage(
        string          $prefix,
        string          $glyph,
        callable        $tone,
        string          $text,
        bool            $padAfter = true,
        bool            $padBefore = true,
    ): void {
        if ($padBefore) {
            $this->console->writeLn('');
        }
        $first = true;
        foreach (preg_split('/\R/u', $text) ?: [$text] as $line) {
            if (trim($line) === '') {
                if (!$first) {
                    $this->console->writeLn($prefix);
                }

                continue;
            }

            if ($first) {
                $this->console->writeLn(
                    $prefix
                    . $glyph
                    . self::SEP
                    . $this->lineTimestamp()
                    . self::SEP
                    . $tone($line),
                );
                $first = false;
                continue;
            }

            $this->console->writeLn($prefix . '  ' . $tone($line));
        }
        if ($padAfter) {
            $this->console->writeLn('');
        }
    }

    protected function lineTimestamp(): string
    {
        return $this->dim(date('H:i:s'));
    }

    protected function writeDelegateStart(AgentInterface $agent): void
    {
        $threadId = pathinfo($agent->threadId(), PATHINFO_EXTENSION);
        $profileId = $agent->profile()->id;

        $head = $this->paint('↳ ' . $threadId, Colors::FC_LIGHT_MAGENTA | Colors::AT_BOLD);

        $label = $profileId !== '' ? self::SEP . $this->delegateProfileId($profileId) : '';

        $this->console->writeLn($this->linePrefix($agent) . $head . $label);
    }

    protected function hasPriorAssistantReply(Thread $thread): bool
    {
        foreach ($thread->turns as $turn) {
            foreach ($turn->completions as $completion) {
                if ($completion->output instanceof AssistantMessage) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function systemMessage(string $prompt): ?string
    {
        $text = trim($prompt);

        return $text !== '' ? $text : null;
    }

    protected function linePrefix(AgentInterface $agent): string
    {
        return str_repeat('    ', $agent->depth());
    }

    protected function delegateProfileId(string $profileId): string
    {
        return $this->paint($profileId, Colors::FC_LIGHT_MAGENTA | Colors::AT_BOLD);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function toolLabel(string $name, array $arguments): string
    {
        $raw = $arguments['.caption'] ?? null;
        $caption = is_string($raw) ? trim($raw) : '';

        if ($caption !== '' && $caption !== $name) {
            return $this->toolCaption($caption) . self::SEP . $this->toolName($name);
        }

        if ($caption !== '') {
            return $this->toolName($caption);
        }

        return $this->toolName($name);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function formatArguments(array $arguments): string
    {
        $args = [];
        foreach ($arguments as $key => $value) {
            if (is_string($key) && str_starts_with($key, '.')) {
                continue;
            }
            $args[$key] = $value;
        }

        if ($args === []) {
            return '{}';
        }

        if ($this->rawTools) {
            $encoded = json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $encoded ?: '{}';
        }

        // Working mode: `key:value` pairs on one line, trimmed to a readable width.
        $parts = [];
        foreach ($args as $key => $value) {
            $parts[] = $key . ':' . $this->inlineValue($value);
        }

        return $this->clipArgument(implode(' ', $parts));
    }

    protected function inlineValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '' : $encoded;
    }

    protected function clipArgument(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        if (mb_strlen($text, 'UTF-8') <= self::ARGUMENT_PREVIEW_CHARS) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::ARGUMENT_PREVIEW_CHARS - 1, 'UTF-8')) . '…';
    }

    protected function resultContent(mixed $result): string
    {
        return Json::text($result);
    }

    protected function toolMeta(int $bytes, float $receiveElapsed, float $executeElapsed): string
    {
        $parts = [];
        if ($bytes > 0) {
            $parts[] = $bytes >= 1024 ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B';
        }

        $totalLabel = $this->formatElapsedSeconds($receiveElapsed + $executeElapsed);
        if ($totalLabel !== '') {
            $parts[] = $totalLabel;
        }

        return $parts === [] ? '' : implode(self::SEP, $parts);
    }

    protected function formatElapsedSeconds(float $elapsed): string
    {
        if ($elapsed >= 1) {
            return round($elapsed, 1) . 's';
        }

        if ($elapsed > 0) {
            return round($elapsed * 1000) . 'ms';
        }

        return '';
    }

    /**
     * Turn an escaped JSON payload into readable lines.
     *
     * Tool results arrive JSON-encoded, so a file body is one long line with literal `\n`.
     * Decoding the JSON string lets the transcript show the same text the model received.
     * Non-JSON content is returned unchanged.
     */
    protected function expandJsonString(string $content): string
    {
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return $content;
        }

        $parts = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $parts[] = $key . ': ' . ($encoded === false ? '' : $encoded);

                continue;
            }

            // A single-text payload (e.g. read) is just the file body — no key noise.
            $parts[] = count($decoded) === 1 && $key === 'content' ? $value : $key . ': ' . $value;
        }

        return implode("\n", $parts);
    }

    protected function assistantText(string $content): string
    {
        $text = $this->stripEnvelopeTags($content);

        $lines = preg_split('/\R/u', trim($text)) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            if ($line === '' || preg_match('/^\[\d+\]\s*$/', $line) === 1) {
                continue;
            }

            $clean[] = $line;
        }

        $text = implode("\n", $clean);
        $text = preg_replace('/(\R\s*){3,}/', "\n\n", trim($text)) ?? trim($text);

        return trim($text);
    }

    /**
     * Streaming delta: preserve leading/trailing spaces across chunks (no trim / line fold).
     */
    protected function assistantDelta(string $delta): string
    {
        if ($delta === '') {
            return '';
        }

        $text = $delta;
        foreach (self::ENVELOPE_PATTERNS as $pattern) {
            $text = preg_replace($pattern, '', $text) ?? $text;
        }

        return $text;
    }

    /** Consume one pending LLM mark for this agent (first tool / schema line of the step). */
    protected function consumeLlmMark(AgentInterface $agent): string
    {
        $id = $agent->threadId();
        if (!isset($this->llmMarkPending[$id])) {
            return '';
        }

        unset($this->llmMarkPending[$id]);

        return self::SEP . $this->assistantGlyph();
    }

    protected function toolGlyph(): string
    {
        return $this->paint(self::ICON_TOOL, Colors::FC_LIGHT_CYAN);
    }

    protected function instructSuccessGlyph(): string
    {
        return $this->paint(self::ICON_SCHEMA_RESULT, Colors::FC_LIGHT_GREEN | Colors::AT_BOLD);
    }

    protected function instructFailGlyph(): string
    {
        return $this->paint(self::ICON_SCHEMA_FAIL, Colors::FC_LIGHT_RED | Colors::AT_BOLD);
    }

    protected function assistantGlyph(): string
    {
        return $this->paint(self::ICON_ASSISTANT, Colors::FC_LIGHT_YELLOW | Colors::AT_BOLD);
    }

    protected function userGlyph(): string
    {
        return $this->paint(self::ICON_USER, Colors::FC_LIGHT_GREEN | Colors::AT_BOLD);
    }

    protected function toolCaption(string $text): string
    {
        return $this->secondary($text);
    }

    protected function toolName(string $text): string
    {
        return $this->paint($text, Colors::FC_LIGHT_CYAN | Colors::AT_BOLD);
    }

    protected function systemTone(string $text): string
    {
        return $this->paint($text, Colors::FC_LIGHT_BLUE | Colors::AT_BOLD | Colors::AT_DIM);
    }

    protected function body(string $text): string
    {
        return $this->paint($text, Colors::FC_LIGHT_WHITE);
    }

    protected function userTone(string $text): string
    {
        return $this->paint($text, Colors::FC_LIGHT_GREEN);
    }

    protected function dim(string $text): string
    {
        return $this->paint($text, Colors::FC_GRAY | Colors::AT_DIM);
    }

    /** Tool args and result previews — secondary but readable. */
    protected function secondary(string $text): string
    {
        return $this->paint($text, Colors::FC_LIGHT_WHITE | Colors::AT_DIM);
    }

    protected function error(string $text): string
    {
        return $this->paint($text, Colors::FC_LIGHT_RED | Colors::AT_BOLD);
    }

    protected function paint(string $text, int $style): string
    {
        if (!$this->console->isSupportColor()) {
            return $text;
        }

        return $this->console->colorize($text, $style);
    }

    /** @var list<string> */
    protected const array ENVELOPE_PATTERNS = [
        '#<think>.*?</think>#is',
        '#<thinking>.*?</thinking>#is',
    ];

    protected function stripEnvelopeTags(string $content): string
    {
        $text = $content;
        foreach (self::ENVELOPE_PATTERNS as $pattern) {
            $text = preg_replace($pattern, '', $text) ?? $text;
        }

        $text = preg_replace('/(\R\s*){3,}/', "\n\n", trim($text)) ?? trim($text);

        return trim($text);
    }
}
