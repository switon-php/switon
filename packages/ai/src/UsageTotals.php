<?php

declare(strict_types=1);

namespace Switon\Ai;

use JsonSerializable;

use function get_object_vars;
use function property_exists;
use function round;

/**
 * Completion totals — one turn, or a datadir's cumulative own-slice (`totals.json`).
 *
 * Guidance: `updatedAt` is the seal time of that bag. A turn totals object leaves it null.
 */
class UsageTotals implements JsonSerializable
{
    public function __construct(
        public ?TokenUsage $usage = null,
        public int $llmCalls = 0,
        public int $toolCalls = 0,
        public float $llmWall = 0.0,
        public ?float $updatedAt = null,
    ) {
    }

    public function __clone(): void
    {
        if ($this->usage !== null) {
            $this->usage = clone $this->usage;
        }
    }

    /** Fold another totals bag. Does not change `$updatedAt`. */
    public function add(self $other): void
    {
        if ($other->usage !== null) {
            if ($this->usage === null) {
                $this->usage = new TokenUsage();
            }
            $this->usage->add($other->usage);
        }
        $this->llmCalls += $other->llmCalls;
        $this->toolCalls += $other->toolCalls;
        $this->llmWall += $other->llmWall;
    }

    public function hasActivity(): bool
    {
        return $this->llmCalls > 0
            || $this->toolCalls > 0
            || $this->usage !== null
            || $this->llmWall > 0.0;
    }

    /**
     * @return array<string, mixed>
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
        if (isset($data['usage'])) {
            $data['usage'] = TokenUsage::decode($data['usage']);
        }

        $totals = new static();
        foreach ($data as $key => $value) {
            if (!property_exists($totals, $key)) {
                continue;
            }
            $totals->$key = $value;
        }
        $totals->llmWall = round($totals->llmWall, 3);

        return $totals;
    }
}
