<?php

declare(strict_types=1);

namespace Switon\Ai;

use JsonSerializable;

/**
 * Worker identity of one as-tool run, stored on {@see AsToolResult::$call}.
 *
 * Guidance: UI transport, not a provider {@see ToolCall}; provider metadata stays on the host call.
 *
 * @see \Switon\Agency\Toolkit\AsToolToolkit
 */
class AsToolCall implements JsonSerializable
{
    /**
     * @param string $id Host wire call id (same as the {@see AsToolResult} it belongs to)
     * @param string $threadId Child thread leaf; the UI binds the sub-thread card from it
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $threadId = '',
        public string $agent = '',
        public string $model = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['class' => static::class] + get_object_vars($this);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): static
    {
        $call = new static();
        foreach ($data as $key => $value) {
            if (!property_exists($call, $key)) {
                continue;
            }

            $call->$key = $value;
        }

        return $call;
    }
}
