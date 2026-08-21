<?php

declare(strict_types=1);

namespace Switon\Langfuse\Observability;

/**
 * One finished OTLP span handed to {@see \Switon\Langfuse\ClientInterface}.
 *
 * Ids are hex (`traceId` 32 chars, `spanId`/`parentSpanId` 16); times are Unix seconds.
 */
class Span
{
    /**
     * @param array<string, mixed> $attributes OTLP attributes; a null value is dropped on encode
     */
    public function __construct(
        public string $name,
        public string $traceId,
        public string $spanId,
        public float $startUnix,
        public float $endUnix,
        public array $attributes = [],
        public string $parentSpanId = '',
        public string $statusMessage = '',
        public bool $isError = false,
    ) {
    }
}
