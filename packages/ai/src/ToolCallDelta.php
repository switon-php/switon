<?php

declare(strict_types=1);

namespace Switon\Ai;

use JsonSerializable;

/**
 * Streaming {@see ToolCall} fragment ({@see StreamDelta::$toolCallDeltas}).
 *
 * Merged fragments keep the earliest `$announcedAt`.
 *
 * Guidance: fragments accumulate in providers and materialize into {@see ToolCall} on aggregate;
 * not persisted — `jsonSerialize()` / `decode()` serve round-trip tests only.
 */
class ToolCallDelta implements JsonSerializable
{
    /**
     * @param array<string, mixed> $passback
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $partialJson = '',
        public array  $passback = [],
        public ?float $announcedAt = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['class' => static::class] + get_object_vars($this);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): static
    {
        $delta = new static();
        foreach ($data as $key => $value) {
            if (!property_exists($delta, $key)) {
                continue;
            }

            $delta->$key = $value;
        }

        return $delta;
    }
}
