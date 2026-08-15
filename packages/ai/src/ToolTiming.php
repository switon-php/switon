<?php

declare(strict_types=1);

namespace Switon\Ai;

use JsonSerializable;

/** Wall-time phases for one tool handler invoke on {@see ToolResult}. */
class ToolTiming implements JsonSerializable
{
    public function __construct(
        /** Stream announce → args ready (Receiving); 0 when non-stream. */
        public float $receive = 0.0,
        /** Handler invoke wall seconds (Executing). */
        public float $execute = 0.0,
    ) {
    }

    /** Receiving phase from the call clocks; 0 when either clock is missing. */
    public static function of(ToolCall $call, float $execute): static
    {
        $receive = 0.0;
        if ($call->announcedAt !== null && $call->argsReadyAt !== null) {
            $receive = round($call->argsReadyAt - $call->announcedAt, 3);
        }

        return new static($receive, $execute);
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
