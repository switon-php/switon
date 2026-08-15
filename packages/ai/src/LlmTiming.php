<?php

declare(strict_types=1);

namespace Switon\Ai;

use JsonSerializable;

/** Wall-time phases for one LLM complete/stream call on {@see Completion}. */
class LlmTiming implements JsonSerializable
{
    public function __construct(
        /** Entire complete/stream wall seconds. */
        public float $wall = 0.0,
        /** Request → first stream delta; 0 when non-stream or unknown. */
        public float $ttft = 0.0,
        /** Reasoning stream phase seconds; 0 when none or unknown. */
        public float $reasoning = 0.0,
        /** Unix seconds (fractional) when the LLM call began; 0 when unset. End ≈ start + wall. */
        public float $start = 0.0,
    ) {
    }

    public static function of(
        float $wall = 0.0,
        float $ttft = 0.0,
        float $reasoning = 0.0,
        float $start = 0.0,
    ): static {
        return new static($wall, $ttft, $reasoning, $start);
    }

    /**
     * @return array<string, float>
     */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): static
    {
        $timing = new static();
        foreach ($data as $key => $value) {
            if (!property_exists($timing, $key)) {
                continue;
            }
            $timing->$key = (float)$value;
        }

        return $timing;
    }
}
