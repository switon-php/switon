<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit\Fixtures;

use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\SystemMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Model;
use Switon\Ai\Thread;
use Switon\Ai\TokenUsage;
use Switon\Ai\ToolCall;

/** @internal Test double for {@see LlmInterface}. */
class StubLlm implements LlmInterface
{
    /** @var list<Completion> */
    public array $responses = [];

    /** @var list<list<\Switon\Ai\Message>> */
    public array $requests = [];

    public function complete(
        Thread $thread,
        Charter $charter,
    ): Completion {
        $completion = $thread->lastCompletion();
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
        $this->requests[] = [...$prefix, ...$history, $completion->input];

        if ($this->responses === []) {
            $filled = Completion::of($completion->input, new AssistantMessage(''));
            $filled->finishReason = 'stop';
            $filled->usage = new TokenUsage();

            return $this->adopt($completion, $filled);
        }

        $response = array_shift($this->responses);
        $filled = Completion::of($completion->input, $response->output);
        $filled->finishReason = $response->finishReason;
        $filled->usage = $response->usage;

        return $this->adopt($completion, $filled);
    }

    public function stream(
        Thread $thread,
        Charter $charter,
        ?callable $onDelta = null,
        ?callable $shouldAbort = null,
    ): Completion {
        return $this->complete($thread, $charter);
    }

    public function model(): Model
    {
        return Model::of(model: 'stub', window: 128_000);
    }

    public function window(): int
    {
        return 128_000;
    }





    protected function adopt(Completion $completion, Completion $filled): Completion
    {
        $completion->output = $filled->output;
        $completion->finishReason = $filled->finishReason;
        $completion->usage = $filled->usage;

        return $completion;
    }

    public static function assistant(string $content): Completion
    {
        $completion = Completion::of(UserMessage::of(''), new AssistantMessage($content));
        $completion->finishReason = 'stop';
        $completion->usage = new TokenUsage();

        return $completion;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function assistantWithToolCall(
        string $name,
        array $arguments = [],
        string $callId = 'call_1',
    ): Completion {
        $completion = Completion::of(
            UserMessage::of(''),
            new AssistantMessage('', [new ToolCall($callId, $name, $arguments)]),
        );
        $completion->finishReason = 'tool_calls';
        $completion->usage = new TokenUsage();

        return $completion;
    }
}
