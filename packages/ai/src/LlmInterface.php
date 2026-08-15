<?php

declare(strict_types=1);

namespace Switon\Ai;

use Switon\Ai\Suspension\Continuation;

/**
 * Completion port for one catalog model (bound at create time).
 *
 * Guidance: resolve via {@see \Switon\Llm\LlmProvidersInterface::get()}; provider catalog and HTTP
 * stay with the client package.
 *
 * Wire contract: encode existing turns in order without inventing rows, skip the hung tip's
 * output, append its input, keep {@see Charter::$prompt} separate, and encode typed objects.
 * {@see Continuation} mail and message attributes never enter the wire.
 *
 * Road-signs:
 * - Built-ins: {@see \Switon\Llm\OpenAi} · {@see \Switon\Llm\Anthropic}
 * - Overflow: {@see Exception\ContextLengthExceededException} → the loop suspends on {@see Suspension\RetryInterrupt::REASON_CONTEXT_OVERFLOW}
 * - Cancel: {@see Completion::REASON_CANCEL} keeps content, drops partial tool calls
 */
interface LlmInterface
{
    /**
     * Fill the hung tip ({@see Thread::lastCompletion()}) — the caller appends it before
     * the call, and the same object is returned (the LLM does not append).
     */
    public function complete(Thread $thread, Charter $charter): Completion;

    /**
     * Stream into the hung tip ({@see Thread::lastCompletion()}); caller appends it before the call.
     * Abort (`$shouldAbort` → true) sets {@see Completion::REASON_CANCEL} — content/reasoning
     * kept, toolCalls dropped (partial JSON must not land), usage best-effort, no throw; a callback
     * throw is not abort — it surfaces as stream failure.
     * Each fragment is also dispatched as {@see \Switon\Llm\Event\CompletionDelta} — the global
     * observability tap; `$onDelta` stays the caller-private channel.
     *
     * @param null|callable(StreamDelta): void $onDelta
     * @param null|callable(): bool $shouldAbort
     */
    public function stream(Thread $thread, Charter $charter, ?callable $onDelta = null, ?callable $shouldAbort = null): Completion;

    /** Bound {@see Model}. */
    public function model(): Model;

    /** Context window (tokens) from {@see model()}. */
    public function window(): int;
}
