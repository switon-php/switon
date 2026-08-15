<?php

declare(strict_types=1);

namespace Switon\Ai;

use JsonSerializable;

/**
 * One provider-requested tool call row; provider `passback` and local `attributes` stay here.
 *
 * Guidance: Storage uses `class` in `jsonSerialize()`; load via `$data['class']::decode($data)`.
 */
class ToolCall implements JsonSerializable
{
    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $passback
     * @param array<string, mixed> $attributes Never on the provider wire.
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array  $arguments = [],
        public array  $passback = [],
        public ?float $announcedAt = null,
        public ?float $argsReadyAt = null,
        public array  $attributes = [],
    ) {
    }

    /** This call was already reviewed. {@see ExtensionInterface::reviewTool()} is not asked again. */
    public bool $reviewed = false;

    /** Host stop flag for this call. */
    public bool $cancelled = false;

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['class' => static::class] + get_object_vars($this);
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function decode(array $item): static
    {
        $call = new static();
        foreach ($item as $key => $value) {
            if (!property_exists($call, $key)) {
                continue;
            }

            $call->$key = $value;
        }

        return $call;
    }
}
