<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Ai\Envelope;
use Swoole\Coroutine\Channel;

/** In-flight inbox item: {@see Envelope} plus queue timestamps. */
class Delivery
{
    public function __construct(
        public Envelope $envelope,
        /** Enqueue time (microtime); stamped in {@see Agent::deliver()} / {@see Agent::run()}. */
        public float    $since,
        public ?Channel $done = null,
    ) {
    }
}
