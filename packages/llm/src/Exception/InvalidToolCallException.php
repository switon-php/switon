<?php

declare(strict_types=1);

namespace Switon\Llm\Exception;

use function is_int;
use function is_string;

/**
 * Use when a provider tool call cannot become a {@see \Switon\Ai\ToolCall}: it lacks identity the
 * wire needs, or a stream fragment can be placed nowhere.
 *
 * Guidance: arguments that fail JSON decode stay on {@see \Switon\Ai\Exception\InvalidToolArgumentsException}
 * — this exception is about call identity only, so a caller must not retry it as bad args.
 */
class InvalidToolCallException extends InvalidResponseException
{
    /** Absent wire key, e.g. `id` or `function.name`; empty when the thrower did not name one. */
    public function missing(): string
    {
        $missing = $this->context['missing'] ?? '';

        return is_string($missing) ? $missing : '';
    }

    /** Provider stream slot when known; null for buffered (non-stream) responses. */
    public function index(): ?int
    {
        $index = $this->context['index'] ?? null;

        return is_int($index) ? $index : null;
    }
}
