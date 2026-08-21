<?php

declare(strict_types=1);

namespace Switon\Langfuse;

use Switon\Langfuse\Observability\Span;

/**
 * Langfuse OTLP export ({@see https://langfuse.com/integrations/native/opentelemetry}).
 *
 * Road-signs:
 * - Consumer: {@see \Switon\Langfuse\Observability\LlmListener} maps provider events to spans
 * - Wire: one OTLP/JSON request to `/api/public/otel/v1/traces` per export batch
 * - Queue: with coroutine hooks `ingest()` enqueues; `shutdown()` drains before the worker exits
 * - Failures are logged and never thrown into the run
 */
interface ClientInterface
{
    /**
     * Configured Langfuse host; empty means the component is not enabled.
     *
     * Read by {@see ServiceProvider::boot()} to decide whether to register the listener — it is a
     * registration switch, not a business API.
     */
    public function baseUrl(): string;

    /**
     * @param list<Span> $spans Finished spans; ids are hex and times are Unix seconds.
     */
    public function ingest(array $spans): void;

    /**
     * Stop accepting exports, wait for every accepted batch (including the one in flight), and let
     * the export worker exit.
     *
     * Call before process teardown: `ingest()` returning does not mean the span reached Langfuse
     * under coroutine hooks.
     */
    public function shutdown(float $timeout = 5.0): void;
}
