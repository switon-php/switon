<?php

declare(strict_types=1);

namespace Switon\Ai;

use JsonSerializable;

/**
 * Provider token counts and cache-hit metrics for one completion.
 *
 * Guidance: missing wire fields default to `0`; no step usage leaves {@see Completion::$usage} null.
 */
class TokenUsage implements JsonSerializable
{
    public function __construct(
        public int    $input = 0,
        public int    $output = 0,
        public int    $cacheRead = 0,
        public int    $cacheWrite = 0,
        public int    $reasoning = 0,
        public ?float $hit = null,
        public ?float $ideal = null,
    ) {
    }

    public function total(): int
    {
        return $this->input + $this->output;
    }

    public function uncached(): int
    {
        return $this->input - $this->cacheRead - $this->cacheWrite;
    }

    /** Stamp `hit = cacheRead/input`; append-only ideal = `prev.input/input`. */
    public function stampHit(?self $prev = null): void
    {
        $this->hit = $this->input > 0 ? round(min(1.0, $this->cacheRead / $this->input), 3) : null;
        if ($prev === null || $this->input <= 0 || $prev->input > $this->input) {
            $this->ideal = null;

            return;
        }

        $this->ideal = round($prev->input / $this->input, 3);
    }

    public function add(self $other): void
    {
        foreach (['input', 'output', 'cacheRead', 'cacheWrite', 'reasoning'] as $key) {
            $this->$key += $other->$key;
        }
        $this->hit = null;
        $this->ideal = null;
    }

    /**
     * @return array<string, int|float|null>
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
        $usage = new static();
        foreach ($data as $key => $value) {
            if (!property_exists($usage, $key)) {
                continue;
            }
            $usage->$key = $value;
        }

        return $usage;
    }
}
