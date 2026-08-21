<?php

declare(strict_types=1);

namespace Switon\Langfuse\Tests\Fixtures;

use Switon\Langfuse\ClientInterface;
use Switon\Langfuse\Observability\Span;

class RecordingClient implements ClientInterface
{
    /** @var list<list<Span>> */
    public array $ingests = [];

    public function __construct(protected string $baseUrl = 'https://cloud.langfuse.com')
    {
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function ingest(array $spans): void
    {
        $this->ingests[] = $spans;
    }

    public function flush(float $timeout = 5.0): void
    {
        // Records are captured on ingest; nothing is buffered here.
    }

    public function shutdown(float $timeout = 5.0): void
    {
    }

    /** Only span of the only export; fails when the listener exported more than once. */
    public function single(): Span
    {
        return $this->ingests[0][0];
    }

    /** Span at `$batch`/`$index` of the export list. */
    public function span(int $batch, int $index = 0): Span
    {
        return $this->ingests[$batch][$index];
    }

    /** Every span of one export batch, in the order the listener built them. */
    public function batch(int $batch = 0): array
    {
        return $this->ingests[$batch];
    }

    /** Orchestrating span of one export batch; it sits between the root and the observation. */
    public function step(int $batch = 0): Span
    {
        return $this->ingests[$batch][1];
    }

    /** Observation span of one export batch; the trace root is index 0, the step span index 1. */
    public function observation(int $batch = 0): Span
    {
        return $this->ingests[$batch][2];
    }

    /** Trace root span shared by every export of one trace. */
    public function root(int $batch = 0): Span
    {
        return $this->ingests[$batch][0];
    }
}
