<?php

declare(strict_types=1);

namespace Switon\Ai;

use Closure;
use Switon\Ai\Message\UserMessage;
use Throwable;

/**
 * Inbox mail: one {@see Letter}, the model id, and the chosen `$effort`.
 *
 * Deliver through {@see AgentInterface::deliver()} / {@see AgentInterface::run()}; in flight it
 * becomes {@see \Switon\Agency\Delivery}. `$onSettled` runs when that drive settles.
 */
class Envelope
{
    /** @var null|Closure(AgentInterface, Turn|Throwable): void */
    public ?Closure $onSettled = null;

    public function __construct(
        public Letter $letter,
        public string $model,
        public ?string $effort = null,
        public ?Turn  $from = null,
    ) {
    }

    /** String letter → {@see UserMessage}; otherwise pass through. */
    public static function of(
        Letter|string $letter,
        string        $model,
        ?string       $effort = null,
        ?Turn         $from = null,
    ): static {
        return new static(
            is_string($letter) ? UserMessage::of($letter) : $letter,
            $model,
            $effort,
            $from,
        );
    }
}
