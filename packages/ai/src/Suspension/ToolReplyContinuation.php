<?php

declare(strict_types=1);

namespace Switon\Ai\Suspension;

/**
 * Inbox mail: external reply for a tool call suspended on {@see ToolReplyInterrupt}.
 */
class ToolReplyContinuation extends Continuation
{
    public function __construct(
        public string $callId,
        public mixed  $reply = null,
    ) {
    }

    public static function of(string $callId, mixed $reply): static
    {
        return new static($callId, $reply);
    }

    /** @return class-string<ToolReplyInterrupt> */
    public function pairsWith(): string
    {
        return ToolReplyInterrupt::class;
    }
}
