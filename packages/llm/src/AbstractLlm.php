<?php

declare(strict_types=1);

namespace Switon\Llm;

use Exception;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Exception\ContextLengthExceededException;
use Switon\Ai\Exception\InvalidToolArgumentsException;
use Switon\Ai\LlmInterface;
use Switon\Ai\LlmTiming;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Model;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\Part\RefusalPart;
use Switon\Ai\StreamDelta;
use Switon\Ai\Thread;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolCallDelta;
use Switon\Ai\Turn;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Core\Json;
use Switon\Llm\Event\CompletionDelta;
use Switon\Llm\Event\CompletionFailed;
use Switon\Llm\Event\CompletionRequesting;
use Switon\Llm\Event\CompletionResponded;
use Switon\Llm\Exception\HttpStatusException;
use Switon\Llm\Exception\InvalidResponseException;
use Switon\Llm\Exception\InvalidToolCallException;
use Switon\Llm\Http\HttpClientInterface;
use Throwable;

use function array_unshift;
use function is_array;
use function is_string;
use function json_decode;
use function trim;

/**
 * LLM client skeleton: configuration, completion orchestration, and lifecycle events.
 *
 * Road-signs:
 * - Contract: {@see \Switon\Ai\LlmInterface} (`complete()` / `stream()` / `model()` / `window()`)
 * - Wire encode: walk `Thread::$turns`, skip the hung tip, encode typed tool calls / media / results
 * - Media: {@see RefReaderInterface::resolve()} decides URL vs local bytes vs unload
 * - Overflow: {@see ContextOverflowCheckerInterface} → {@see \Switon\Ai\Exception\ContextLengthExceededException}
 * - Events: {@see \Switon\Llm\Event\HttpRequesting} · {@see \Switon\Llm\Event\CompletionRequesting}
 *
 * @see \Switon\Llm\OpenAi
 * @see \Switon\Llm\Anthropic
 */
abstract class AbstractLlm implements LlmInterface
{
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected ContextOverflowCheckerInterface $contextOverflowChecker;
    #[Autowired] protected ClockInterface $clock;
    #[Autowired] protected RefReaderInterface $refReader;
    #[Autowired] protected HttpClientInterface $httpClient;

    #[Autowired] protected string $baseUrl = '';
    #[Autowired] protected string $apiKey = '';
    /**
     * Catalog record from {@see LlmProviders::get()}.
     *
     * Wire name is {@see Model::$model}. Window, image, video, effort levels,
     * extra, and the picker line live on this object.
     */
    #[Autowired] protected Model $model;
    /** HTTP seconds: non-stream = wall clock; stream = idle stall without bytes. */
    #[Autowired] protected int $timeout = 60;
    /** `null` = open turn only; `true`/`false` = always/never pass back assistant reasoning. */
    #[Autowired] protected ?bool $passbackReasoning = null;

    /** Wire notice when media is omitted / unreadable (historical tool media, bad ref, unsupported scheme). */
    public const string UNLOADED_MEDIA_NOTICE = 'Re-request with `media` to view.';

    public function model(): Model
    {
        return $this->model;
    }

    public function window(): int
    {
        return $this->model->window;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    protected function passbackReasoningFor(Turn $turn): bool
    {
        if ($this->passbackReasoning === false) {
            return false;
        }
        if ($this->passbackReasoning === true) {
            return true;
        }

        return !$turn->isSettled();
    }

    public function complete(Thread $thread, Charter $charter): Completion
    {
        $completion = $thread->lastCompletion();
        $requesting = new CompletionRequesting($this, $thread, $completion, $charter, stream: false);
        $this->eventDispatcher->dispatch($requesting);
        $started = $this->clock->microtime();
        $requesting->completion->timing ??= new LlmTiming();
        $requesting->completion->timing->start = $this->clock->now();

        try {
            $this->wireComplete($requesting);
            $requesting->completion->timing ??= new LlmTiming();
            $requesting->completion->timing->wall = $this->clock->elapsed($started);
            $this->stampReasoningElapsedFromOutput($requesting->completion);
            $this->stampUsageRates($requesting->thread, $requesting->completion);

            $this->eventDispatcher->dispatch(new CompletionResponded(
                $requesting->sender,
                $requesting->thread,
                $requesting->completion,
                $requesting->charter,
                $requesting->stream,
            ));

            return $requesting->completion;
        } catch (Throwable $e) {
            $requesting->completion->timing ??= new LlmTiming();
            $requesting->completion->timing->wall = $this->clock->elapsed($started);
            $e = $this->asContextOverflow($e);
            [$httpStatus, $httpBody] = $this->httpFailure($e);
            $this->eventDispatcher->dispatch(new CompletionFailed(
                $requesting->sender,
                $requesting->thread,
                $requesting->completion,
                $e,
                $requesting->charter,
                $httpStatus,
                $httpBody,
                $requesting->stream,
            ));
            throw $e;
        }
    }

    public function stream(Thread $thread, Charter $charter, ?callable $onDelta = null, ?callable $shouldAbort = null): Completion
    {
        $completion = $thread->lastCompletion();
        $requesting = new CompletionRequesting($this, $thread, $completion, $charter, stream: true);
        $this->eventDispatcher->dispatch($requesting);
        $started = $this->clock->microtime();
        $requesting->completion->timing ??= new LlmTiming();
        $requesting->completion->timing->start = $this->clock->now();

        $pollAbort = $shouldAbort === null ? null : function () use ($shouldAbort, $requesting): bool {
            if (!$shouldAbort()) {
                return false;
            }

            if (!$requesting->completion->isCancelled()) {
                $requesting->completion->finishReason = Completion::REASON_CANCEL;
            }

            return true;
        };

        try {
            $reasoningTracker = new ReasoningPhaseTracker($this->clock);
            $deltaIndex = 0;
            $ttftStamped = false;
            $this->wireStream(
                $requesting,
                function (StreamDelta $streamDelta) use (&$deltaIndex, $requesting, $reasoningTracker, $started, &$ttftStamped, $onDelta): void {
                    if (!$ttftStamped) {
                        $requesting->completion->timing ??= new LlmTiming();
                        $requesting->completion->timing->ttft = $this->clock->elapsed($started);
                        $ttftStamped = true;
                    }
                    $reasoningTracker->observe($streamDelta, $requesting->completion);
                    $streamDelta->at = $this->clock->now();
                    $onDelta === null || $onDelta($streamDelta);
                    $this->eventDispatcher->dispatch(new CompletionDelta(
                        $requesting->sender,
                        $requesting->thread,
                        $streamDelta,
                        $requesting->completion,
                        $requesting->charter,
                        $deltaIndex,
                    ));
                    $deltaIndex++;
                },
                $pollAbort,
            );

            $reasoningTracker->finalize($requesting->completion);
            $requesting->completion->timing ??= new LlmTiming();
            $requesting->completion->timing->wall = $this->clock->elapsed($started);
            $this->stampUsageRates($requesting->thread, $requesting->completion);

            $this->eventDispatcher->dispatch(new CompletionResponded(
                $requesting->sender,
                $requesting->thread,
                $requesting->completion,
                $requesting->charter,
                $requesting->stream,
            ));

            return $requesting->completion;
        } catch (Throwable $e) {
            $requesting->completion->timing ??= new LlmTiming();
            $requesting->completion->timing->wall = $this->clock->elapsed($started);
            $e = $this->asContextOverflow($e);
            [$httpStatus, $httpBody] = $this->httpFailure($e);
            $this->eventDispatcher->dispatch(new CompletionFailed(
                $requesting->sender,
                $requesting->thread,
                $requesting->completion,
                $e,
                $requesting->charter,
                $httpStatus,
                $httpBody,
                $requesting->stream,
            ));
            throw $e;
        }
    }

    /**
     * Snapshot {@see \Switon\Ai\TokenUsage::$hit} / {@see \Switon\Ai\TokenUsage::$ideal} from prev LLM usage.
     * as_reply dispatch (last tool has {@code as_reply}): host only hands off — no hit/ideal rates.
     */
    protected function stampUsageRates(Thread $thread, Completion $completion): void
    {
        $usage = $completion->usage;
        if ($usage === null) {
            return;
        }

        // Dispatch hop is not a cacheable answer step; leave hit/ideal unset (tokens stay).
        if ($this->isAsReplyDispatch($completion)) {
            return;
        }

        // Signal / tool-result rows never reached the provider; a reply with `usage === null`
        // still counts as the previous measurement slot, so `ideal` goes null instead of reaching back.
        $previous = null;
        $seen = false;
        for ($ti = count($thread->turns) - 1; $ti >= 0; --$ti) {
            $completions = $thread->turns[$ti]->completions;
            for ($ci = count($completions) - 1; $ci >= 0; --$ci) {
                $row = $completions[$ci];
                if (!$seen) {
                    $seen = $row === $completion;
                    continue;
                }
                if (!$row->output instanceof AssistantMessage) {
                    continue;
                }
                $previous = $row;
                break 2;
            }
        }
        $usage->stampHit($previous?->usage);
    }

    /** Last toolCall requests as_reply — host hands off to child; no paraphrase request after. */
    protected function isAsReplyDispatch(Completion $completion): bool
    {
        $toolCalls = $completion->toolCalls();
        $last = $toolCalls[array_key_last($toolCalls)] ?? null;
        if ($last === null) {
            return false;
        }

        $raw = $last->arguments['as_reply'] ?? false;

        return $raw === true || $raw === 1 || $raw === 'true' || $raw === '1';
    }

    /**
     * @return array{0: ?int, 1: ?string}
     */
    protected function httpFailure(Throwable $error): array
    {
        $http = $error instanceof HttpStatusException
            ? $error
            : ($error->getPrevious() instanceof HttpStatusException ? $error->getPrevious() : null);
        if (!$http instanceof HttpStatusException) {
            return [null, null];
        }

        return [$http->getStatusCode(), $http->getResponseBody()];
    }

    protected function raiseContextOverflow(?string $code, string $message, ?Exception $previous = null): void
    {
        if (!$this->contextOverflowChecker->matches($code, $message)) {
            return;
        }

        ContextLengthExceededException::raise(
            'LLM context length exceeded: {message}',
            [
                'message' => $message,
                'model' => $this->model->model,
                'window' => $this->window(),
            ],
            previous: $previous,
        );
    }

    protected function asContextOverflow(Throwable $error): Throwable
    {
        if (!$error instanceof HttpStatusException) {
            return $error;
        }

        [$code, $message] = $this->providerError($error->getResponseBody());
        if (!$this->contextOverflowChecker->matches($code, $message)) {
            return $error;
        }

        return ContextLengthExceededException::of(
            'LLM context length exceeded: {message}',
            [
                'message' => $message !== '' ? $message : $error->getMessage(),
                'model' => $this->model->model,
                'window' => $this->window(),
            ],
            previous: $error,
        );
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    protected function providerError(string $body): array
    {
        try {
            $decoded = Json::parse($body);
        } catch (Throwable) {
            return [null, $body];
        }
        if (!is_array($decoded)) {
            return [null, $body];
        }

        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : null;
        if ($error === null) {
            return [null, $body];
        }

        $code = is_string($error['code'] ?? null) ? $error['code'] : null;
        $message = is_string($error['message'] ?? null) && $error['message'] !== ''
            ? $error['message']
            : $body;

        return [$code, $message];
    }

    protected function stampReasoningElapsedFromOutput(Completion $completion): void
    {
        $output = $completion->output;
        if (!$output instanceof AssistantMessage || trim((string) $output->reasoning) === '') {
            return;
        }

        if ($output->toolCalls !== []) {
            return;
        }
        if ($output->content !== null && trim($output->content) !== '') {
            return;
        }
        foreach ($output->parts as $part) {
            if ($part instanceof RefusalPart && trim($part->refusal) !== '') {
                return;
            }
        }

        $completion->timing ??= new LlmTiming();
        $completion->timing->reasoning = $completion->timing->wall;
    }

    /**
     * Fold stream deltas into {@see Completion::$output} / finishReason / usage.
     * On cancel: skip tool finalize (no partial {@see ToolCall}s).
     *
     * @param list<StreamDelta> $deltas
     */
    protected function aggregateDeltas(array $deltas, Completion $completion): void
    {
        $endedEarly = $completion->finishReason !== null;
        if ($deltas === []) {
            if ($endedEarly) {
                $completion->output = new AssistantMessage('', [], null);

                return;
            }

            InvalidResponseException::raise('LLM stream ended without an assistant response.');
        }

        $content = '';
        $refusal = '';
        $reasoning = null;
        $reasoningBuf = '';
        $finishReason = null;
        $usage = null;
        $toolCallDeltas = [];
        $passback = [];

        foreach ($deltas as $streamDelta) {
            $content .= $streamDelta->content;
            $refusal .= $streamDelta->refusal;
            if ($streamDelta->reasoning !== null) {
                $reasoningBuf .= $streamDelta->reasoning;
                $reasoning = $reasoningBuf;
            }

            if ($streamDelta->finishReason !== null) {
                $finishReason = $streamDelta->finishReason;
            }

            if ($streamDelta->usage !== null) {
                if ($usage === null) {
                    $usage = $streamDelta->usage;
                } else {
                    $usage->add($streamDelta->usage);
                }
            }

            if ($streamDelta->passback !== []) {
                $passback = array_replace($passback, $streamDelta->passback);
            }

            foreach ($streamDelta->toolCallDeltas as $toolCallDelta) {
                $toolCallDeltas[] = $toolCallDelta;
            }
        }

        $parts = $refusal !== '' ? [RefusalPart::of($refusal)] : [];
        $text = ($content === '' && $parts !== []) ? null : $content;

        // Cancel = completion wrap-up only: never decode/heal tool JSON (partial must not land).
        if ($completion->isCancelled()) {
            $completion->output = new AssistantMessage($text, [], $reasoning, $passback, $parts);
            $completion->usage = $usage;

            return;
        }

        $completion->output = new AssistantMessage(
            $text,
            $this->finalizeToolCalls($toolCallDeltas),
            $reasoning,
            $passback,
            $parts,
        );
        if (!$endedEarly) {
            $completion->finishReason = $finishReason;
        }
        $completion->usage = $usage;
    }

    /**
     * Assemble provider tool-call fragments. Index slots win; otherwise match by id.
     * Earliest {@see ToolCallDelta::$announcedAt} stays the Receiving origin.
     *
     * @param list<ToolCallDelta> $deltas
     *
     * @return list<ToolCallDelta>
     *
     * @throws InvalidToolCallException when a fragment can be placed nowhere, or a call finishes without id/name
     */
    protected function mergeToolCallDeltas(array $deltas): array
    {
        if ($deltas === []) {
            return [];
        }

        /** @var array<int, ToolCallDelta> $slots */
        $slots = [];
        /** @var list<int> $order */
        $order = [];

        foreach ($deltas as $delta) {
            $index = $delta->passback['index'] ?? null;
            if (is_int($index)) {
                if (!isset($slots[$index])) {
                    $order[] = $index;
                    $slots[$index] = $delta;
                    continue;
                }

                $slots[$index] = $this->mergeToolCallDelta($slots[$index], $delta);
                continue;
            }

            if ($delta->id === '') {
                InvalidToolCallException::raise('LLM tool call fragment has neither index nor id.', ['missing' => 'index']);
            }

            foreach ($order as $slotIndex) {
                if ($slots[$slotIndex]->id === $delta->id) {
                    $slots[$slotIndex] = $this->mergeToolCallDelta($slots[$slotIndex], $delta);
                    continue 2;
                }
            }

            $fallbackIndex = count($order);
            $order[] = $fallbackIndex;
            $slots[$fallbackIndex] = $delta;
        }

        $merged = [];
        foreach ($order as $index) {
            $delta = $slots[$index];
            if ($delta->id === '') {
                InvalidToolCallException::raise('LLM tool call is missing its id.', [
                    'missing' => 'id',
                    'index' => $index,
                ]);
            }
            if ($delta->name === '') {
                InvalidToolCallException::raise('LLM tool call is missing its function name.', [
                    'missing' => 'function.name',
                    'index' => $index,
                ]);
            }

            $passback = $delta->passback;
            unset($passback['index']);

            $merged[] = new ToolCallDelta(
                $delta->id,
                $delta->name,
                $delta->partialJson,
                $passback,
                $delta->announcedAt,
            );
        }

        return $merged;
    }

    protected function mergeToolCallDelta(ToolCallDelta $existing, ToolCallDelta $delta): ToolCallDelta
    {
        $passback = $delta->passback !== []
            ? array_replace($existing->passback, $delta->passback)
            : $existing->passback;

        return new ToolCallDelta(
            $delta->id !== '' ? $delta->id : $existing->id,
            $delta->name !== '' ? $delta->name : $existing->name,
            $existing->partialJson . $delta->partialJson,
            $passback,
            $this->earlierAnnouncedAt($existing->announcedAt, $delta->announcedAt),
        );
    }

    protected function earlierAnnouncedAt(?float $left, ?float $right): ?float
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return min($left, $right);
    }

    /**
     * @param list<ToolCallDelta> $deltas
     *
     * @return list<ToolCall>
     */
    protected function finalizeToolCalls(array $deltas): array
    {
        $toolCalls = [];
        $argsReadyAt = $this->clock->microtime();
        foreach ($this->mergeToolCallDeltas($deltas) as $delta) {
            $payload = $this->decodeToolArguments($delta->partialJson);
            if ($payload === null) {
                throw new InvalidToolArgumentsException(
                    'Tool "{name}" arguments must decode to a JSON object.',
                    new ToolCall($delta->id, $delta->name),
                    $delta->partialJson,
                    ['name' => $delta->name],
                );
            }

            $toolCalls[] = new ToolCall(
                $delta->id,
                $delta->name,
                $payload,
                passback: $delta->passback,
                announcedAt: $delta->announcedAt,
                argsReadyAt: $argsReadyAt,
            );
        }

        return $toolCalls;
    }

    /**
     * @return array<string, mixed>|null Null when arguments are not a JSON object.
     */
    protected function decodeToolArguments(string $json): ?array
    {
        if ($json === '') {
            return [];
        }

        foreach (['', '}', '}}', ']}', '}}}'] as $suffix) {
            $payload = json_decode($json . $suffix, true);
            if (is_array($payload)) {
                return $payload;
            }
        }

        return null;
    }

    /**
     * Prepend leading text to provider content blocks; collapse to a bare string when all are text.
     *
     * @param list<array<string, mixed>> $blocks
     *
     * @return string|list<array<string, mixed>>
     */
    protected function withLeadingText(string $leading, array $blocks): string|array
    {
        if ($leading !== '') {
            array_unshift($blocks, ['type' => 'text', 'text' => $leading]);
        }
        if ($blocks === []) {
            return $leading;
        }

        foreach ($blocks as $block) {
            if (($block['type'] ?? '') !== 'text') {
                return $blocks;
            }
        }

        if (count($blocks) === 1) {
            return $blocks[0]['text'];
        }

        return $blocks;
    }

    protected function unloadedMediaNotice(?string $ref = null): string
    {
        if ($ref === null || $ref === '') {
            return self::UNLOADED_MEDIA_NOTICE;
        }

        return self::UNLOADED_MEDIA_NOTICE . ' ref: ' . $ref;
    }

    /**
     * Merge {@see MediaPart::$extra} onto a provider media object; structural keys stay owned by encode.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    protected function withMediaExtra(array $fields, MediaPart $part): array
    {
        static $reserved = [
            'url' => true,
            'type' => true,
            'source' => true,
            'data' => true,
            'media_type' => true,
            'image_url' => true,
            'video_url' => true,
        ];
        foreach ($part->extra as $key => $value) {
            if (!is_string($key) || $key === '' || isset($reserved[$key])) {
                continue;
            }
            $fields[$key] = $value;
        }

        return $fields;
    }

    /**
     * Catalog `extra` on {@see model()}, then the charter effort
     * ({@see effortPayload()}). Keys already on `$payload` stay.
     *
     * Effort wins over an `extra` key for the same field: the request pick is more specific than
     * static config. Recursive merge so a nested payload (Anthropic `output_config`) keeps sibling keys.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function withExtra(array $payload, Charter $charter): array
    {
        $extra = array_replace_recursive($this->model()->extra, $this->effortPayload($charter->effort));

        return $payload + $extra;
    }

    /**
     * Wire keys for a charter effort level. Empty when the level is absent, so the field is omitted.
     *
     * @return array<string, mixed>
     */
    protected function effortPayload(?string $effort): array
    {
        return [];
    }

    abstract protected function wireComplete(CompletionRequesting $requesting): void;

    /**
     * HTTP SSE drive shared by every provider: read each line, keep the deltas that parse,
     * let the caller observe them, then fold the batch into `$completion`.
     *
     * @param callable(StreamDelta): void $onDelta
     * @param null|callable(): bool $shouldAbort
     */
    protected function wireStream(CompletionRequesting $requesting, callable $onDelta, ?callable $shouldAbort = null): void
    {
        [$url, $headers, $payload] = $this->buildWireRequest($requesting);
        $deltas = [];
        $completion = $requesting->completion;

        $this->httpClient->stream(
            $url,
            $headers,
            $payload,
            $this->timeout,
            function (string $line) use (&$deltas, $onDelta, $completion): void {
                $streamDelta = $this->readStreamLine($line);
                if ($streamDelta === null) {
                    return;
                }

                if ($streamDelta->finishReason !== null) {
                    $completion->finishReason = $streamDelta->finishReason;
                }

                $deltas[] = $streamDelta;
                $onDelta($streamDelta);
            },
            $completion->finishReason,
            $shouldAbort,
        );

        $this->aggregateDeltas($deltas, $completion);
    }

    /**
     * One SSE frame to a delta. The provider-specific end sentinel (for example `[DONE]`) comes
     * from {@see streamEndMarker()} and is compared before JSON decode, so it never reaches the
     * parser; every decoded frame still passes {@see raiseProviderError()}.
     */
    protected function readStreamLine(string $line): ?StreamDelta
    {
        $line = trim($line);
        if ($line === '' || !str_starts_with($line, 'data:')) {
            return null;
        }

        $data = trim(substr($line, 5));
        $endMarker = $this->streamEndMarker();
        if ($data === '' || ($endMarker !== '' && $data === $endMarker)) {
            return null;
        }

        try {
            $decoded = Json::parse($data);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        return $this->streamDeltaFromFrame($decoded);
    }

    /** Provider end-of-stream sentinel sent as a `data:` payload; empty when the stream just closes. */
    protected function streamEndMarker(): string
    {
        return '';
    }

    /**
     * Provider hook: map one decoded SSE frame; null for frames that carry no delta.
     *
     * @param array<string, mixed> $decoded
     */
    abstract protected function streamDeltaFromFrame(array $decoded): ?StreamDelta;
}
