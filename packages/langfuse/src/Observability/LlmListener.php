<?php

declare(strict_types=1);

namespace Switon\Langfuse\Observability;

use Switon\Ai\AsToolResult;
use Switon\Ai\Charter;
use Switon\Ai\Completion;
use Switon\Ai\Event\DeliverySettled;
use Switon\Ai\Event\TurnSuspended;
use Switon\Ai\LlmInterface;
use Switon\Ai\Message;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\FoldedMessage;
use Switon\Ai\Message\SystemMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Part\AskPart;
use Switon\Ai\Part\ContentPart;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\Part\PinnedPart;
use Switon\Ai\Part\RefusalPart;
use Switon\Ai\Part\TextPart;
use Switon\Ai\Suspension\RetryInterrupt;
use Switon\Ai\Suspension\ToolInterrupt;
use Switon\Ai\Thread;
use Switon\Ai\TokenUsage;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Ai\ToolSchema;
use Switon\Ai\Turn;
use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Json;
use Switon\Eventing\Attribute\EventListener;
use Switon\Eventing\ObservabilityProbe;
use Switon\Langfuse\ClientInterface;
use Switon\Langfuse\Otlp;
use Switon\Llm\Event\CompletionFailed;
use Switon\Llm\Event\CompletionResponded;
use Switon\Tooling\Event\ToolFinished;
use Throwable;

use function count;
use function is_array;
use function is_string;
use function max;
use function mb_strlen;
use function mb_substr;
use function microtime;
use function min;
use function preg_match;
use function preg_replace;
use function trim;

/**
 * Maps LLM lifecycle events to Langfuse OTLP spans.
 *
 * Guidance: one trace per open {@see Turn::$turnId}; with no turn, the trace id is {@see Completion::$completeId}.
 *
 * Road-signs:
 * - Session: host {@see Thread::$id}; the trace name prefers the catalog profile, then falls back to the thread id. A sub-agent `{host}.{leaf}` (`t10001`…) shares that host
 * - Export: one OTLP request per LLM call or tool call, when that call returns or fails
 * - Spans: a `generation` per {@see Completion::$completeId} holding the charter and its tool schemas; each step is an `agent` span, and a finished or suspended tool is a `tool` span beside that generation, not under it
 * - Usage: exclusive buckets from {@see TokenUsage} (input / cache / output / reasoning)
 * - Times: {@see \Switon\Ai\LlmTiming} on the completion when present
 */
class LlmListener implements ObservabilityProbe
{
    #[Autowired] protected ClientInterface $client;
    #[Autowired] protected AppInterface $app;

    #[EventListener]
    public function onCompletionResponded(CompletionResponded $event): void
    {
        $completion = $event->completion;
        if (trim($completion->completeId) === '') {
            return;
        }

        $traceId = $this->traceId($event->thread, $completion);
        $turn = $this->turnForCompletion($event->thread, $completion);
        $assistant = $completion->output instanceof AssistantMessage ? $completion->output : null;
        $output = $this->serializeOutput($completion->output);

        $attributes = $this->traceAttributes($event->thread, $turn);
        $attributes['langfuse.observation.type'] = 'generation';
        $attributes['langfuse.observation.input'] = Json::stringify($this->wirePayload($event->charter, $event->thread, $completion));
        $attributes['langfuse.observation.output'] = Json::stringify($output);
        $attributes['langfuse.observation.model.name'] = $this->reportedModel($event->sender, $turn);
        $modelParameters = $this->modelParameters($turn);
        if ($modelParameters !== []) {
            $attributes['langfuse.observation.model.parameters'] = Json::stringify($modelParameters);
        }
        $attributes += $this->metadataAttributes('langfuse.observation.metadata', $this->generationMetadata(
            $completion,
            $event->charter,
            $event->stream,
            $turn,
        ));
        $finishReason = (string) $completion->finishReason;
        if ($finishReason !== '') {
            $attributes['langfuse.observation.metadata.finish_reason'] = $finishReason;
        }

        $usage = $this->serializeUsage($completion->usage);
        if ($usage !== null) {
            $attributes['langfuse.observation.usage_details'] = Json::stringify($usage);
        }

        // Cache hit ratio and the append-only ideal ratio are Switon-side measurements Langfuse does
        // not derive, so they ride on metadata where they stay filterable.
        $usageMeta = $this->usageMetadata($completion->usage);
        if ($usageMeta !== []) {
            $attributes += $this->metadataAttributes('langfuse.observation.metadata', $usageMeta);
        }

        // Application metadata is flat and filterable; keys this component already wrote are kept
        // intact and the app's colliding key moves to `attr.<key>` instead of being dropped.
        $reserved = array_fill_keys(array_map(
            static fn (string $key): string => str_replace('langfuse.observation.metadata.', '', $key),
            array_keys($attributes),
        ), '');
        $app = $completion->attributes;
        if ($completion->output instanceof Message) {
            $app += $completion->output->attributes;
        }
        $attributes += $this->metadataAttributes('langfuse.observation.metadata', $this->appAttributes($app, $reserved));

        $times = $this->generationTimes($completion);
        if (isset($times['completionStart'])) {
            $attributes['langfuse.observation.completion_start_time'] = Otlp::isoFromUnix($times['completionStart']);
        }

        $stepSpanId = Otlp::spanId($traceId, 'step-' . $completion->step);

        $this->client->ingest([
            $this->rootSpan($event->thread, $traceId, $times['start'], $times['end'], $turn),
            $this->stepSpan($event->thread, $traceId, $completion->step, $times['start'], $times['end'], $turn),
            new Span(
                $this->generationName($completion->input, $assistant),
                $traceId,
                $this->generationSpanId($completion),
                $times['start'],
                $times['end'],
                $attributes,
                $stepSpanId,
            ),
        ]);
    }

    #[EventListener]
    public function onCompletionFailed(CompletionFailed $event): void
    {
        $completion = $event->completion;
        if (trim($completion->completeId) === '') {
            return;
        }

        $times = $this->generationTimes($completion);
        $turn = $this->turnForCompletion($event->thread, $completion);
        $attributes = $this->traceAttributes($event->thread, $turn);
        $attributes['langfuse.observation.type'] = 'generation';
        $attributes['langfuse.observation.level'] = 'ERROR';
        $attributes['langfuse.observation.status_message'] = $event->error->getMessage();
        $attributes['langfuse.observation.input'] = Json::stringify($this->wirePayload($event->charter, $event->thread, $completion));
        $attributes['langfuse.observation.model.name'] = $this->reportedModel($event->sender, $turn);
        $modelParameters = $this->modelParameters($turn);
        if ($modelParameters !== []) {
            $attributes['langfuse.observation.model.parameters'] = Json::stringify($modelParameters);
        }
        $attributes += $this->metadataAttributes('langfuse.observation.metadata', $this->generationMetadata(
            $completion,
            $event->charter,
            $event->stream,
            $turn,
        ));
        $attributes += $this->metadataAttributes('langfuse.observation.metadata', [
            'http_status' => $event->httpStatus === null ? '' : (string) $event->httpStatus,
            'exception' => $event->error::class,
        ]);

        $traceId = $this->traceId($event->thread, $completion);

        $this->client->ingest([
            $this->rootSpan($event->thread, $traceId, $times['start'], $times['end'], $turn),
            $this->stepSpan($event->thread, $traceId, $completion->step, $times['start'], $times['end'], $turn),
            new Span(
                'failed-llm-call',
                $traceId,
                $this->generationSpanId($completion),
                $times['start'],
                $times['end'],
                $attributes,
                Otlp::spanId($traceId, 'step-' . $completion->step),
                $event->error->getMessage(),
                true,
            ),
        ]);
    }

    /**
     * Re-send the trace root with the turn's final status; the generation export always sees `running`.
     */
    #[EventListener]
    public function onDeliverySettled(DeliverySettled $event): void
    {
        $thread = $event->sender->thread();
        $turnId = trim($event->turn->turnId);
        if ($turnId === '') {
            return;
        }

        $end = $event->turn->closedAt > 0 ? $event->turn->closedAt : microtime(true);
        $start = $event->turn->openedAt > 0 ? $event->turn->openedAt : $end;

        $this->client->ingest([
            $this->rootSpan($thread, Otlp::traceId($turnId), $start, $end, $event->turn),
        ]);
    }

    #[EventListener]
    public function onToolFinished(ToolFinished $event): void
    {
        $call = $event->call;
        if (trim($call->id) === '') {
            return;
        }

        $thread = $event->agent->thread();
        $turn = $this->turnForCall($thread, $call->id);
        if ($turn === null) {
            return;
        }

        $parent = $this->completionForCall($thread, $call->id);
        $traceId = Otlp::traceId($turn->turnId);
        $end = microtime(true);
        $timing = $event->result->timing;
        $elapsed = ($timing->receive ?? 0.0) + ($timing->execute ?? 0.0);
        $start = $elapsed > 0 ? $end - $elapsed : $end;
        $metadata = [
            'thread_id' => $event->agent->threadId(),
            'tool_call_id' => $call->id,
        ];
        if ($timing !== null) {
            $metadata['timing'] = $timing->jsonSerialize();
        }
        if ($event->result->recall !== null && $event->result->recall !== '') {
            $metadata['recall'] = $event->result->recall;
        }
        if ($event->result instanceof AsToolResult && $event->result->call->threadId !== '') {
            $metadata['child_thread_id'] = $event->result->call->threadId;
            if ($event->result->call->agent !== '') {
                $metadata['child_agent'] = $event->result->call->agent;
            }
        }

        $attributes = $this->traceAttributes($thread, $turn);
        $attributes['langfuse.observation.type'] = $this->toolObservationType($event->result);
        $attributes['langfuse.observation.input'] = Json::stringify($this->wireArguments($call->arguments));
        $result = $this->resultContent($event->result);
        if ($result !== null) {
            $attributes['langfuse.observation.output'] = Json::stringify($result);
        }
        $reserved = array_fill_keys(array_map(
            static fn (string $key): string => str_replace('langfuse.observation.metadata.', '', $key),
            array_keys($attributes),
        ), '');
        $app = $call->attributes + $event->result->attributes;
        $attributes += $this->metadataAttributes(
            'langfuse.observation.metadata',
            $this->appAttributes($app, $reserved + array_fill_keys(array_keys($metadata), '')),
        );
        $attributes += $this->metadataAttributes('langfuse.observation.metadata', $metadata);
        if ($parent !== null) {
            $attributes['langfuse.observation.metadata.generation_id'] = $parent->completeId;
        }

        $status = '';
        if ($event->result->isError) {
            $status = $this->errorStatus($event->result);
            $attributes['langfuse.observation.level'] = 'ERROR';
            if ($status !== '') {
                $attributes['langfuse.observation.status_message'] = $status;
            }
        }

        $step = $parent === null ? 0 : $parent->step;

        $this->client->ingest([
            $this->rootSpan($thread, $traceId, $start, $end, $turn),
            $this->stepSpan($thread, $traceId, $step, $start, $end, $turn),
            new Span(
                $this->toolSpanName($call, $event->result),
                $traceId,
                Otlp::spanId($call->id),
                $start,
                $end,
                $attributes,
                Otlp::spanId($traceId, 'step-' . $step),
                $status,
                $event->result->isError,
            ),
        ]);
    }

    /**
     * A tool interrupt never emits {@see ToolFinished}. Record the suspend on its own span so a later
     * real tool run keeps the call id.
     */
    #[EventListener]
    public function onTurnSuspended(TurnSuspended $event): void
    {
        $turnId = trim($event->turn->turnId);
        if ($turnId === '') {
            return;
        }

        $interrupt = $event->interrupt;
        $end = microtime(true);
        $start = $interrupt->suspendedAt ?? $end;
        $thread = $event->sender->thread();
        // The suspend belongs to the turn that raised it, which is not necessarily the last one.
        $turn = $event->turn;

        if ($interrupt instanceof ToolInterrupt && $interrupt->callId !== '') {
            $parent = $this->completionForCall($thread, $interrupt->callId);
            $traceId = Otlp::traceId($turnId);
            $attributes = $this->traceAttributes($thread, $turn);
            $attributes['langfuse.observation.type'] = 'tool';
            $attributes['langfuse.observation.level'] = 'WARNING';
            $attributes['langfuse.observation.status_message'] = 'suspended ' . $this->shortClass($interrupt::class);
            $attributes['langfuse.observation.input'] = Json::stringify($this->wireArguments($interrupt->arguments));
            $attributes += $this->metadataAttributes('langfuse.observation.metadata', [
                'thread_id' => $event->sender->threadId(),
                'tool_call_id' => $interrupt->callId,
                'interrupt' => $interrupt::class,
            ]);
            if ($parent !== null) {
                $attributes['langfuse.observation.metadata.generation_id'] = $parent->completeId;
            }

            $step = $parent === null ? 0 : $parent->step;

            $this->client->ingest([
                $this->rootSpan($thread, $traceId, $start, $end, $turn),
                $this->stepSpan($thread, $traceId, $step, $start, $end, $turn),
                new Span(
                    $interrupt->name !== '' ? $interrupt->name : 'tool',
                    $traceId,
                    Otlp::spanId($interrupt->callId, 'suspend'),
                    $start,
                    $end,
                    $attributes,
                    Otlp::spanId($traceId, 'step-' . $step),
                ),
            ]);

            return;
        }

        $name = $interrupt instanceof RetryInterrupt ? $interrupt->reason : $this->shortClass($interrupt::class);
        $detail = $interrupt instanceof RetryInterrupt ? $interrupt->detail : '';
        $traceId = Otlp::traceId($turnId);
        $attributes = $this->traceAttributes($thread, $turn);
        $attributes['langfuse.observation.type'] = 'event';
        $attributes['langfuse.observation.level'] = 'WARNING';
        $attributes['langfuse.observation.status_message'] = $detail !== '' ? $detail : $name;
        $attributes += $this->metadataAttributes('langfuse.observation.metadata', [
            'thread_id' => $event->sender->threadId(),
            'interrupt' => $interrupt::class,
        ]);
        if ($detail !== '') {
            $attributes['langfuse.observation.output'] = $detail;
        }

        $this->client->ingest([
            $this->rootSpan($thread, $traceId, $start, $end, $turn),
            new Span(
                $name,
                $traceId,
                Otlp::spanId($turnId, 'suspend'),
                $start,
                $end,
                $attributes,
                Otlp::spanId($traceId, 'root'),
            ),
        ]);
    }

    /**
     * Trace-level attributes carried by every span so filters work per row.
     *
     * `$turn` is the exporting turn, not necessarily the newest one: a late tool export keeps its own
     * turn's trace. `Turn::$attributes` maps `user_id` → `langfuse.user.id`, `tags` →
     * `langfuse.trace.tags`, and every other key → trace metadata.
     *
     * @return array<string, mixed>
     */
    protected function traceAttributes(Thread $thread, ?Turn $turn = null): array
    {
        $threadId = trim($thread->id);
        $turn ??= $thread->turns === [] ? null : $thread->lastTurn();
        $turnId = $turn === null ? '' : trim($turn->turnId);
        $profileId = $this->profileId($thread);

        // Trace name is a filter/evaluator target, so prefer the catalog profile (a stable action
        // name such as `coding.agent`) over the thread id, which is a per-conversation UUID.
        $attributes = [
            'langfuse.trace.name' => $profileId !== ''
                ? $profileId
                : ($threadId !== '' ? $threadId : ($turnId !== '' ? $turnId : 'llm-call')),
        ];

        $sessionId = $this->sessionId($threadId);
        if ($sessionId !== null) {
            $attributes['langfuse.session.id'] = $sessionId;
        }

        // `environment` is Langfuse's global nav filter: without it every trace lands in `default`
        // and a dev run is indistinguishable from production.
        $environment = trim($this->app->env());
        if ($environment !== '') {
            $attributes['langfuse.environment'] = $environment;
        }

        $release = trim($this->app->version());
        if ($release !== '') {
            $attributes['langfuse.release'] = $release;
        }

        $attributes += $this->metadataAttributes('langfuse.trace.metadata', ['thread_id' => $threadId]);
        if ($turnId !== '') {
            $attributes['langfuse.trace.metadata.turn_id'] = $turnId;
        }

        $turnMeta = $this->turnMetadata($turn);
        if ($turnMeta !== []) {
            $attributes += $this->metadataAttributes('langfuse.trace.metadata', $turnMeta);
        }
        if ($profileId !== '') {
            $attributes['langfuse.trace.metadata.profile_id'] = $profileId;
        }

        if ($turn !== null && $turn->attributes !== []) {
            $attributes += $this->traceAppAttributes($turn->attributes);
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    protected function traceAppAttributes(array $attributes): array
    {
        $rows = [];
        $tags = $this->traceTags($attributes['tags'] ?? null);
        if ($tags !== []) {
            $rows['langfuse.trace.tags'] = $tags;
        }
        $userId = $attributes['user_id'] ?? null;
        if (is_string($userId) && trim($userId) !== '') {
            $rows['langfuse.user.id'] = trim($userId);
        }
        unset($attributes['tags'], $attributes['user_id']);

        $reserved = [
            'thread_id' => '',
            'turn_id' => '',
            'profile_id' => '',
            'status' => '',
            'opened_at' => '',
            'closed_at' => '',
            'effort' => '',
            'llm_calls' => '',
            'tool_calls' => '',
            'llm_wall' => '',
        ];
        $rows += $this->metadataAttributes('langfuse.trace.metadata', $this->appAttributes($attributes, $reserved));

        return $rows;
    }

    /**
     * @return list<string>
     */
    protected function traceTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = [$tags];
        }
        if (!is_array($tags)) {
            return [];
        }

        $out = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            $tag = trim($tag);
            if ($tag !== '') {
                $out[] = $tag;
            }
        }

        return $out;
    }

    /**
     * Turn lifecycle and folded totals: `usageTotals()` already aggregates calls, tools, and wall time.
     *
     * @return array<string, mixed>
     */
    protected function turnMetadata(?Turn $turn): array
    {
        if ($turn === null) {
            return [];
        }

        $meta = [];
        // Written only when settled: an in-flight `running` value races the settle write, and
        // whichever span ends last decides.
        if ($turn->status->settled()) {
            $meta['status'] = $turn->status->value;
        }

        if ($turn->openedAt > 0) {
            $meta['opened_at'] = Otlp::isoFromUnix($turn->openedAt);
        }
        if ($turn->closedAt > 0) {
            $meta['closed_at'] = Otlp::isoFromUnix($turn->closedAt);
        }
        if ($turn->effort !== null && trim($turn->effort) !== '') {
            $meta['effort'] = trim($turn->effort);
        }

        $totals = $turn->usageTotals();
        if ($totals->llmCalls > 0) {
            $meta['llm_calls'] = $totals->llmCalls;
        }
        if ($totals->toolCalls > 0) {
            $meta['tool_calls'] = $totals->toolCalls;
        }
        if ($totals->llmWall > 0) {
            $meta['llm_wall'] = $totals->llmWall;
        }

        return $meta;
    }

    /** Catalog profile bound to this thread; `Switon\Agency\Agent` records it on first use. */
    protected function profileId(Thread $thread): string
    {
        $profileId = $thread->state['profileId'] ?? null;

        return is_string($profileId) ? trim($profileId) : '';
    }

    /**
     * Langfuse exposes `metadata.*` keys as filterable top-level fields; each value stays a string.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, string>
     */
    protected function metadataAttributes(string $prefix, array $metadata): array
    {
        $attributes = [];
        foreach ($metadata as $key => $value) {
            if ($value === null) {
                continue;
            }
            $attributes[$prefix . '.' . $key] = is_array($value)
                ? Json::stringify($value)
                : (is_string($value) ? $value : Json::stringify($value));
        }

        return $attributes;
    }

    /**
     * Application metadata as flat filterable fields; a key this component already writes moves to `attr.<key>`.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, string> $reserved already-written metadata keys
     *
     * @return array<string, string>
     */
    protected function appAttributes(array $attributes, array $reserved): array
    {
        $rows = [];
        foreach ($attributes as $key => $value) {
            if (!is_string($key) || trim($key) === '' || $value === null) {
                continue;
            }
            $name = array_key_exists($key, $reserved) ? 'attr.' . $key : $key;
            $rows[$name] = is_string($value) ? $value : Json::stringify($value);
        }

        return $rows;
    }

    protected function generationSpanId(Completion $completion): string
    {
        return Otlp::spanId($completion->completeId, 'generation');
    }

    /**
     * Trace root span, re-sent on every export under one stable id so OTLP upserts instead of adding rows.
     *
     * It exists so a generation never sits at the root: Langfuse folds root observation metadata into
     * trace metadata, and a resend without input/output erases both. The dialogue therefore always
     * comes from the exported turn, never from the triggering event.
     */
    protected function rootSpan(Thread $thread, string $traceId, float $start, float $end, ?Turn $turn = null): Span
    {
        $turn ??= $thread->turns === [] ? null : $thread->lastTurn();
        $attributes = $this->traceAttributes($thread, $turn);
        $attributes['langfuse.observation.type'] = 'span';

        // The root spans the whole turn, not just the call being exported: every export re-sends it,
        // so a per-call window would leave the trace lasting only as long as the last call.
        [$start, $end] = $this->turnWindow($turn, $start, $end);

        $input = $this->rootInput($turn);
        if ($input !== []) {
            $attributes['langfuse.observation.input'] = Json::stringify($input);
        }

        // Only a *text* reply is written, and it is the same value on every later export. Langfuse
        // applies the last write per field, so repeating one stable value is safe where writing a
        // per-step value (a tool-call message) would let whichever export lands last decide.
        $output = $this->rootOutput($turn);
        if ($output !== null) {
            $attributes['langfuse.observation.output'] = Json::stringify($output);
        }
        return new Span(
            'turn',
            $traceId,
            Otlp::spanId($traceId, 'root'),
            $start,
            $end,
            $attributes,
        );
    }

    /**
     * A dispatched sub-agent is an `agent` observation, not a generic `tool`: Langfuse builds its
     * agent graph from that type, and a `tool` keeps the sub-agent's own steps invisible.
     */
    protected function toolObservationType(ToolResult $result): string
    {
        return $result instanceof AsToolResult && $result->call->threadId !== '' ? 'agent' : 'tool';
    }

    /**
     * Orchestrating `agent` span for one {@see Completion::$step}; the model call and the tools it
     * requested are its children. Typed `agent` to reach Langfuse's agent graph; re-sent per step so
     * its bounds widen instead of duplicating.
     */
    protected function stepSpan(Thread $thread, string $traceId, int $step, float $start, float $end, ?Turn $turn = null): Span
    {
        $turn ??= $thread->turns === [] ? null : $thread->lastTurn();
        $attributes = $this->traceAttributes($thread, $turn);
        $attributes['langfuse.observation.type'] = 'agent';
        $attributes['langfuse.observation.metadata.step'] = (string) $step;

        [$start, $end] = $this->stepWindow($turn, $step, $start, $end);

        return new Span(
            'step-' . $step,
            $traceId,
            Otlp::spanId($traceId, 'step-' . $step),
            $start,
            $end,
            $attributes,
            Otlp::spanId($traceId, 'root'),
        );
    }

    /**
     * Widen a step's bounds to its own completion and the tools that completion requested.
     *
     * @return array{0: float, 1: float}
     */
    protected function stepWindow(?Turn $turn, int $step, float $start, float $end): array
    {
        if ($turn === null) {
            return [$start, $end];
        }

        foreach ($turn->completions as $completion) {
            if ($completion->step !== $step) {
                continue;
            }
            $times = $this->generationTimes($completion);
            $start = min($start, $times['start']);
            $end = max($end, $times['end']);
        }

        return [$start, $end];
    }

    /**
     * Tool arguments as a reader should see them: framework-internal `.`-prefixed keys (such as `.caption`) are wiring.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    protected function wireArguments(array $arguments): array
    {
        return array_filter(
            $arguments,
            static fn (int|string $key): bool => !is_string($key) || !str_starts_with($key, '.'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Conversation the turn opened with: the first completion's input, without the charter prompt.
     *
     * @return list<array<string, mixed>>
     */
    protected function rootInput(?Turn $turn): array
    {
        if ($turn === null || $turn->completions === []) {
            return [];
        }

        return $this->serializeMessages([$turn->completions[0]->input]);
    }

    /**
     * The turn's text reply, if it has produced one. Tool-call-only steps are skipped: they are not an
     * answer, and writing them would make the trace output depend on export order.
     */
    protected function rootOutput(?Turn $turn): string|null
    {
        if ($turn === null) {
            return null;
        }

        foreach (array_reverse($turn->completions) as $completion) {
            if (!$completion->output instanceof AssistantMessage) {
                continue;
            }
            $text = trim((string) ($completion->output->content ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * Widen the given bounds to every completion the open turn already recorded.
     *
     * @return array{0: float, 1: float}
     */
    protected function turnWindow(?Turn $turn, float $start, float $end): array
    {
        if ($turn === null) {
            return [$start, $end];
        }

        foreach ($turn->completions as $completion) {
            $times = $this->generationTimes($completion);
            $start = min($start, $times['start']);
            $end = max($end, $times['end']);
        }

        return [$start, $end];
    }

    protected function errorStatus(ToolResult $result): string
    {
        $content = $result->content;
        if ($content instanceof Throwable) {
            return $this->labeled($content::class, $content->getMessage());
        }

        $wire = $result->wire();
        if (is_string($wire)) {
            return $wire;
        }
        if (is_array($wire)) {
            $message = $wire['message'] ?? null;
            $class = $wire['class'] ?? null;
            if (is_string($message) && $message !== '') {
                return is_string($class) && $class !== '' ? $this->labeled($class, $message) : $message;
            }
        }

        return '';
    }

    protected function labeled(string $class, string $message): string
    {
        $short = $this->shortClass($class);

        return $message === '' ? $short : $short . ': ' . $message;
    }

    protected function shortClass(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    protected function completionForCall(Thread $thread, string $callId): ?Completion
    {
        foreach ($thread->turns as $turn) {
            foreach ($turn->completions as $completion) {
                foreach ($completion->toolCalls() as $call) {
                    if ($call->id === $callId) {
                        return $completion;
                    }
                }
            }
        }

        return null;
    }

    /** Turn that requested `$callId`, else the open turn. Null when the thread carries no turn. */
    protected function turnForCall(Thread $thread, string $callId): ?Turn
    {
        foreach ($thread->turns as $turn) {
            if (trim($turn->turnId) === '') {
                continue;
            }
            foreach ($turn->completions as $completion) {
                foreach ($completion->toolCalls() as $call) {
                    if ($call->id === $callId) {
                        return $turn;
                    }
                }
            }
        }

        if ($thread->turns === []) {
            return null;
        }

        $last = $thread->lastTurn();

        return trim($last->turnId) === '' ? null : $last;
    }

    /** Turn that owns `$completion`, else the open turn. */
    protected function turnForCompletion(Thread $thread, Completion $completion): ?Turn
    {
        foreach ($thread->turns as $turn) {
            foreach ($turn->completions as $candidate) {
                if ($candidate === $completion) {
                    return $turn;
                }
            }
        }

        return $thread->turns === [] ? null : $thread->lastTurn();
    }

    /**
     * @return array<string, mixed>
     */
    protected function generationMetadata(
        Completion $completion,
        Charter $charter,
        bool $stream,
        ?Turn $turn,
    ): array {
        $meta = [
            'stream' => $stream,
            'tool_count' => count($charter->tools),
            'complete_id' => $completion->completeId,
            'turn_id' => $turn === null ? '' : trim($turn->turnId),
            'step' => $completion->step,
            'catalog_model' => $turn === null ? '' : $turn->model,
        ];
        if ($charter->toolChoice !== null && $charter->toolChoice !== '') {
            $meta['tool_choice'] = $charter->toolChoice;
        }
        $tools = $this->charterTools($charter);
        if ($tools !== []) {
            $meta['tools'] = $tools;
        }
        if ($completion->timing !== null) {
            $timing = $completion->timing->jsonSerialize();
            if ($timing !== []) {
                $meta['timing'] = $timing;
            }
        }

        return $meta;
    }

    /**
     * Tools the model was offered, name plus the schema sent on the wire.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    protected function charterTools(Charter $charter): array
    {
        $tools = [];
        foreach ($charter->tools as $name => $schema) {
            if (!$schema instanceof ToolSchema) {
                continue;
            }
            $description = $schema->definition['description'] ?? '';
            $tools[] = [
                'name' => (string) $name,
                'description' => is_string($description) ? $description : '',
                'input_schema' => $schema->jsonSchema(),
            ];
        }

        return $tools;
    }

    /**
     * Wall-clock bounds for a generation.
     * Prefer {@see \Switon\Ai\LlmTiming::$start} + `wall`; else back-date from export time.
     *
     * @return array{start: float, end: float, completionStart?: float}
     */
    protected function generationTimes(Completion $completion): array
    {
        $timing = $completion->timing;
        $startUnix = null;
        $endUnix = null;

        if ($timing !== null && $timing->start > 0) {
            $startUnix = $timing->start;
            $endUnix = $timing->wall > 0 ? $startUnix + $timing->wall : microtime(true);
        } elseif ($timing !== null && $timing->wall > 0) {
            $endUnix = microtime(true);
            $startUnix = $endUnix - $timing->wall;
        } else {
            $endUnix = microtime(true);
            $startUnix = $endUnix;
        }

        $times = [
            'start' => $startUnix,
            'end' => $endUnix,
        ];
        if ($timing !== null && $timing->ttft > 0) {
            $times['completionStart'] = $startUnix + $timing->ttft;
        }

        return $times;
    }

    protected function traceId(Thread $thread, Completion $completion): string
    {
        // A request can arrive on a thread with no turn yet (tests, re-entrant probes) — the
        // completion id is enough to key the trace then.
        $turn = $this->turnForCompletion($thread, $completion);
        $turnId = $turn === null ? '' : trim($turn->turnId);

        return Otlp::traceId($turnId !== '' ? $turnId : $completion->completeId);
    }

    /**
     * Invocation settings Langfuse compares generations by: reasoning effort is what makes two calls
     * to the same model cost different amounts.
     *
     * @return array<string, mixed>
     */
    protected function modelParameters(?Turn $turn): array
    {
        $parameters = [];
        $effort = $turn === null ? '' : trim((string) ($turn->effort ?? ''));
        if ($effort !== '') {
            $parameters['reasoning_effort'] = $effort;
        }

        return $parameters;
    }

    protected function sessionId(string $threadId): ?string
    {
        $threadId = trim($threadId);
        if ($threadId === '') {
            return null;
        }

        return $this->hostThreadId($threadId);
    }

    /**
     * Host id for a thread. Sub-agents are `{host}.{leaf}` where the leaf is `t10001`….
     * A host id such as `profile.root` stays whole.
     */
    protected function hostThreadId(string $threadId): string
    {
        if (preg_match('/^(.*)\.(t[1-9]\d{4,})$/', $threadId, $matches) === 1 && $matches[1] !== '') {
            return $matches[1];
        }

        return $threadId;
    }

    /** Provider wire name. Catalog id stays on metadata when it differs. */
    protected function reportedModel(LlmInterface $llm, ?Turn $turn): string
    {
        $wire = trim($llm->model()->model);
        if ($wire !== '') {
            return $wire;
        }

        return $this->modelName($turn === null ? '' : $turn->model);
    }

    /**
     * Stable action name for a generation. Langfuse matches evaluators, dashboards, and saved filters
     * on names, so reply text never enters one.
     */
    protected function generationName(Message $input, ?AssistantMessage $output = null): string
    {
        if ($output !== null && $output->toolCalls !== []) {
            return 'call-tool';
        }

        if ($input instanceof ToolMessage && $input->results !== []) {
            return 'answer-with-tool-result';
        }

        if ($output !== null) {
            return 'answer';
        }

        return 'llm-call';
    }

    protected function toolSpanName(ToolCall $call, ToolResult $result): string
    {
        $name = $call->name !== '' ? $call->name : 'tool';
        $preview = $this->toolPreview($call, $result);
        if ($preview === '') {
            return $name;
        }

        return $name . ' · ' . $this->truncateLabel($preview, 56);
    }

    /**
     * Short human line for a tool span name: the `.caption` the model wrote, else a scalar argument,
     * else the first line of output. Never raw JSON — the tree shows one row per span.
     */
    protected function toolPreview(ToolCall $call, ToolResult $result): string
    {
        $caption = $this->oneLine($call->arguments['.caption'] ?? null);
        if ($caption !== '') {
            return $caption;
        }

        foreach (['command', 'path', 'query', 'pattern', 'url'] as $key) {
            $line = $this->oneLine($call->arguments[$key] ?? null);
            if ($line !== '') {
                return $line;
            }
        }

        $wire = $result->wire();
        if (is_array($wire)) {
            foreach (['stdout', 'stderr', 'message'] as $key) {
                $line = $this->oneLine($wire[$key] ?? null);
                if ($line !== '') {
                    return $line;
                }
            }

            return '';
        }

        return $this->oneLine(is_string($wire) ? $wire : null);
    }

    protected function oneLine(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);

        return trim($value);
    }

    protected function truncateLabel(string $text, int $max): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1) . '…';
    }

    /**
     * Prompt the provider encodes: charter system text, earlier slots, then this completion's input.
     * The working slot is omitted from history so its reply is not part of the input.
     *
     * @return list<Message>
     */
    protected function wireMessages(Charter $charter, Thread $thread, Completion $working): array
    {
        $prompt = trim($charter->prompt);
        $messages = $prompt !== '' ? [SystemMessage::of($prompt)] : [];

        return array_merge($messages, $this->threadMessages($thread, $working));
    }

    /**
     * Thread slots up to this call: earlier input and output, then the working input.
     * The charter prompt is not a thread message.
     *
     * @return list<Message>
     */
    protected function threadMessages(Thread $thread, Completion $working): array
    {
        $messages = [];
        foreach ($thread->completions() as $completion) {
            if ($completion === $working) {
                continue;
            }
            $messages[] = $completion->input;
            if ($completion->output !== null) {
                $messages[] = $completion->output;
            }
        }
        $messages[] = $working->input;

        return $messages;
    }

    /**
     * Chat rows sent as the generation input.
     *
     * @return list<array<string, mixed>>
     */
    protected function wirePayload(Charter $charter, Thread $thread, Completion $working): array
    {
        return $this->serializeMessages($this->wireMessages($charter, $thread, $working));
    }

    protected function modelName(string $model): string
    {
        $model = trim($model);

        return $model !== '' ? $model : 'unknown';
    }

    /**
     * Chat rows for Langfuse: one `tool` row per {@see ToolMessage} result; a single text block stays a string.
     *
     * @param list<Message> $messages
     *
     * @return list<array<string, mixed>>
     */
    protected function serializeMessages(array $messages): array
    {
        $serialized = [];
        foreach ($messages as $message) {
            foreach ($this->chatMessages($message) as $row) {
                $serialized[] = $row;
            }
        }

        return $serialized;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function chatMessages(Message $message): array
    {
        if ($message instanceof ToolMessage) {
            $rows = [];
            foreach ($message->results as $result) {
                $row = [
                    'role' => $message->role,
                    'content' => $this->content($this->resultContent($result)),
                ];
                if ($result->id !== '') {
                    $row['tool_call_id'] = $result->id;
                }
                if ($result->name !== '') {
                    $row['name'] = $result->name;
                }
                $rows[] = $row;
            }

            return $rows;
        }

        $row = [
            'role' => $message->role,
            'content' => $this->content($this->messageContent($message)),
        ];
        if ($message instanceof AssistantMessage) {
            if ($message->toolCalls !== []) {
                $row['tool_calls'] = array_map(
                    fn (ToolCall $call): array => [
                        'id' => $call->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $call->name,
                            'arguments' => Json::stringify($call->arguments),
                        ],
                    ],
                    $message->toolCalls,
                );
            }
            foreach ($message->parts as $part) {
                if ($part instanceof RefusalPart && $part->refusal !== '') {
                    if ($row['content'] === null) {
                        $row['content'] = $part->refusal;
                    }
                    break;
                }
            }
            if ($message->reasoning !== null && $message->reasoning !== '') {
                $row['thinking'] = [[
                    'type' => 'thinking',
                    'content' => $message->reasoning,
                ]];
            }
        }

        return [$row];
    }

    /** Chat content stays a JSON value; plain scalars pass through, objects become JSON text. */
    protected function content(mixed $value): mixed
    {
        if ($value === null || is_string($value) || is_array($value)) {
            return $value;
        }

        return Json::stringify($value);
    }

    protected function messageContent(Message $message): mixed
    {
        if ($message instanceof AssistantMessage) {
            return ($message->content === null || $message->content === '') ? null : $message->content;
        }

        if ($message instanceof UserMessage) {
            return $this->collapseBlocks($this->partBlocks($message->parts));
        }

        if ($message instanceof FoldedMessage) {
            $blocks = [];
            $excerpt = $message->text();
            if ($excerpt !== null && $excerpt !== '') {
                $blocks[] = ['type' => 'text', 'text' => $excerpt, 'part' => 'text'];
            }
            foreach ($message->parts as $part) {
                if ($part->text === '') {
                    continue;
                }
                $blocks[] = ['type' => 'text', 'text' => $part->text, 'part' => 'pinned'];
            }

            return $this->collapseBlocks($blocks);
        }

        return $this->collapseBlocks($this->wireBlocks($message));
    }

    /**
     * Tool body the thread stored: string or structured `wire()`, then parts. Media is a ref, not bytes.
     */
    protected function resultContent(ToolResult $result): mixed
    {
        if ($result->parts === [] && ($result->recall === null || $result->recall === '')) {
            return $result->wire();
        }

        $blocks = [];
        $wire = $result->wire();
        if (is_string($wire) && $wire !== '') {
            $blocks[] = ['type' => 'text', 'text' => $wire, 'part' => 'text'];
        } elseif (is_array($wire) && $wire !== []) {
            $blocks[] = ['type' => 'text', 'text' => Json::stringify($wire), 'part' => 'text'];
        }
        foreach ($this->partBlocks($result->parts) as $block) {
            $blocks[] = $block;
        }
        if ($result->recall !== null && $result->recall !== '') {
            $blocks[] = ['type' => 'text', 'text' => $result->recall, 'part' => 'recall'];
        }

        return $this->collapseBlocks($blocks);
    }

    /**
     * @param list<ContentPart> $parts
     *
     * @return list<array{type: string, text: string, part: string}>
     */
    protected function partBlocks(array $parts): array
    {
        $blocks = [];
        foreach ($parts as $part) {
            if ($part instanceof TextPart) {
                if ($part->text === '') {
                    continue;
                }
                $blocks[] = [
                    'type' => 'text',
                    'text' => $part->text,
                    'part' => $this->textPartKind($part),
                ];
                continue;
            }
            if ($part instanceof MediaPart && $part->ref !== '') {
                $text = $part->mime !== null && $part->mime !== ''
                    ? $part->ref . ' (' . $part->mime . ')'
                    : $part->ref;
                $blocks[] = ['type' => 'text', 'text' => $text, 'part' => 'media'];
            }
        }

        return $blocks;
    }

    protected function textPartKind(TextPart $part): string
    {
        if ($part instanceof AskPart) {
            return 'ask';
        }
        if ($part instanceof PinnedPart) {
            return 'pinned';
        }

        return 'text';
    }

    /**
     * A lone ask or media ref stays a string so the bubble shows that text.
     * Pinned, model-only text, and a spilled path stay blocks.
     *
     * @param list<array{type: string, text: string, part?: string}> $blocks
     */
    protected function collapseBlocks(array $blocks): mixed
    {
        if ($blocks === []) {
            return null;
        }
        if (count($blocks) === 1) {
            $kind = $blocks[0]['part'] ?? 'ask';
            if ($kind === 'ask' || $kind === 'media') {
                return $blocks[0]['text'];
            }
        }

        return $blocks;
    }

    /**
     * @return list<array{type: string, text: string}>
     */
    protected function wireBlocks(Message $message): array
    {
        $blocks = [];
        foreach ($message->wire() as $block) {
            if ($block['text'] === '') {
                continue;
            }
            $blocks[] = ['type' => 'text', 'text' => $block['text']];
        }

        return $blocks;
    }

    /**
     * Plain text stays a string. Tool calls, refusal, reasoning, and several parts stay a message.
     *
     * @return array<string, mixed>|list<array<string, mixed>>|string|null
     */
    protected function serializeOutput(?Message $message): array|string|null
    {
        if ($message === null) {
            return null;
        }

        $chat = $this->chatMessages($message);
        if ($chat === []) {
            return null;
        }
        if (count($chat) === 1) {
            $extra = array_diff(array_keys($chat[0]), ['role', 'content']);
            if ($extra === [] && is_string($chat[0]['content'])) {
                return $chat[0]['content'];
            }

            return $chat[0];
        }

        return $chat;
    }

    /**
     * Switon's cache-efficiency numbers: `hit` is the cache-read share of the prompt, `ideal` the
     * ratio against the previous call, which tells a reader whether context kept being reused.
     *
     * @return array<string, float>
     */
    protected function usageMetadata(?TokenUsage $usage): array
    {
        if ($usage === null) {
            return [];
        }

        $meta = [];
        if ($usage->hit !== null) {
            $meta['cache_hit'] = $usage->hit;
        }
        if ($usage->ideal !== null) {
            $meta['cache_ideal'] = $usage->ideal;
        }

        return $meta;
    }

    /**
     * Exclusive Langfuse `usageDetails` buckets (no overlapping cache/reasoning counts).
     *
     * @return array<string, int>|null
     */
    protected function serializeUsage(?TokenUsage $usage): ?array
    {
        if ($usage === null) {
            return null;
        }

        $details = [];
        if ($usage->cacheRead > 0 || $usage->cacheWrite > 0) {
            $details['input'] = max(0, $usage->uncached());
            $details['cache_read_input_tokens'] = $usage->cacheRead;
            $details['cache_creation_input_tokens'] = $usage->cacheWrite;
        } elseif ($usage->input > 0) {
            $details['input'] = $usage->input;
        }

        if ($usage->reasoning > 0) {
            $details['output'] = max(0, $usage->output - $usage->reasoning);
            $details['reasoning_tokens'] = $usage->reasoning;
        } elseif ($usage->output > 0) {
            $details['output'] = $usage->output;
        }

        $total = $usage->total();
        if ($total > 0) {
            $details['total'] = $total;
        }

        return $details === [] ? null : $details;
    }
}
