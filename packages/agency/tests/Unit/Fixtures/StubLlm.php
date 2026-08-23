<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit\Fixtures;

use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Charter;
use Switon\Ai\StreamDelta;
use Switon\Ai\LlmInterface;
use Switon\Ai\Completion;
use Switon\Ai\Thread;
use Switon\Ai\Message;
use Switon\Ai\Message\SystemMessage;
use Switon\Ai\Model;
use Switon\Ai\TokenUsage;
use Switon\Ai\ToolCall;
use Switon\Ai\Message\UserMessage;
use Closure;
use Throwable;

use function str_contains;
use function trim;

class StubLlm implements LlmInterface
{
    /** @var list<Completion|callable(Thread, Message, Charter): Completion> */
    public array $responses = [];

    public ?Throwable $throwOnce = null;

    /** @var list<Throwable> Thrown in order from {@see respond()} before normal responses. */
    public array $throwQueue = [];

    /** @var list<list<Message>> */
    public array $requests = [];

    public string $titleResult = 'Session title';

    public int $completeCalls = 0;

    public int $streamCalls = 0;

    public int $onDeltaCalls = 0;

    public int $shouldAbortPolls = 0;

    /** @var list<string> */
    public array $streamDeltas = [];

    /** @var null|Closure(int): void */
    public ?Closure $onStreamDelta = null;

    public function complete(
        Thread $thread,
        Charter $charter,
    ): Completion {
        $this->completeCalls++;

        return $this->respond($charter, $thread, $thread->lastCompletion());
    }

    public function stream(
        Thread $thread,
        Charter $charter,
        ?callable $onDelta = null,
        ?callable $shouldAbort = null,
    ): Completion {
        $this->streamCalls++;
        $completion = $thread->lastCompletion();

        if ($this->streamDeltas !== []) {
            $streamed = '';
            foreach ($this->streamDeltas as $index => $content) {
                if ($shouldAbort !== null) {
                    $this->shouldAbortPolls++;
                    if ($shouldAbort()) {
                        if (!$completion->isCancelled()) {
                            $completion->finishReason = Completion::REASON_CANCEL;
                        }
                        break;
                    }
                }

                $streamed .= $content;
                if ($onDelta !== null) {
                    $onDelta(new StreamDelta(content: $content));
                    $this->onDeltaCalls++;
                }

                $this->onStreamDelta?->__invoke($index);
            }

            if ($completion->isCancelled()) {
                $completion->output = new AssistantMessage($streamed);

                return $completion;
            }
        } elseif ($onDelta !== null) {
            $onDelta(new StreamDelta(content: 'stream-delta'));
            $this->onDeltaCalls++;
        }

        return $this->respond($charter, $thread, $completion);
    }

    protected function respond(
        Charter $charter,
        Thread $thread,
        Completion $completion,
    ): Completion {
        $input = $completion->input;
        $history = [];
        foreach ($thread->completions() as $row) {
            if ($row === $completion) {
                continue;
            }
            $history[] = $row->input;
            if ($row->output !== null) {
                $history[] = $row->output;
            }
        }
        $prompt = trim($charter->prompt);
        $prefix = $prompt !== '' ? [SystemMessage::of($prompt)] : [];
        $this->requests[] = [...$prefix, ...$history, $input];

        if ($this->throwQueue !== []) {
            throw array_shift($this->throwQueue);
        }

        if ($this->throwOnce !== null) {
            $throw = $this->throwOnce;
            $this->throwOnce = null;
            throw $throw;
        }

        if ($this->isTitleRequest($charter)) {
            $filled = Completion::of($input, new AssistantMessage($this->titleResult));
            $filled->completeId = 'stub-title';
            $filled->finishReason = 'stop';
            $filled->usage = new TokenUsage();

            return $this->adopt($completion, $filled);
        }

        if ($this->responses === []) {
            $filled = Completion::of($input, new AssistantMessage(''));
            $filled->completeId = 'stub-empty';
            $filled->finishReason = 'stop';
            $filled->usage = new TokenUsage();

            return $this->adopt($completion, $filled);
        }

        $response = array_shift($this->responses);
        if (is_callable($response)) {
            $response = $response($thread, $input, $charter);
        }

        return $this->adopt($completion, $response);
    }

    protected function adopt(Completion $completion, Completion $response): Completion
    {
        $completion->output = $response->output;
        if (!$completion->isCancelled()) {
            $completion->finishReason = $response->finishReason;
        }
        $completion->usage = $response->usage;

        return $completion;
    }

    public function model(): Model
    {
        return Model::of(model: 'stub', window: 128_000);
    }

    public function window(): int
    {
        return 128_000;
    }




    public static function assistant(string $content): Completion
    {
        $completion = Completion::of(UserMessage::of(''), new AssistantMessage($content));
        $completion->completeId = 'stub-assistant';
        $completion->finishReason = 'stop';
        $completion->usage = new TokenUsage();

        return $completion;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $caption
     */
    /** @var int Monotonic call id suffix so repeated stub tool steps are not treated as already settled. */
    protected static int $callSeq = 0;

    public static function assistantWithToolCall(
        string $name,
        array $arguments = [],
        string $callId = '',
        ?array $caption = ['caption' => 'Running tool.'],
    ): Completion {
        $args = $arguments;
        if ($caption !== null && !isset($args['.caption'])) {
            $args['.caption'] = $caption['caption'] ?? '';
        }
        if ($callId === '') {
            $callId = 'call_' . (++self::$callSeq);
        }

        $call = new ToolCall($callId, $name, $args);
        $completion = Completion::of(UserMessage::of(''), new AssistantMessage('', [$call]));
        $completion->completeId = 'stub-tool-' . $callId;
        $completion->finishReason = 'tool_calls';
        $completion->usage = new TokenUsage();

        return $completion;
    }

    protected function isTitleRequest(Charter $charter): bool
    {
        return str_contains($charter->prompt, 'conversation titler');
    }

}
