<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Fixtures;

use Switon\Agency\Delivery;
use Switon\Agency\Exception\DeliveryException;
use Switon\Ai\Envelope;
use Switon\Ai\Turn;
use Swoole\Coroutine\Channel;
use Throwable;

/**
 * Short inbox push timeout for backpressure unit tests.
 */
class FastInboxAgent extends RecordingRootAgent
{
    public function deliver(Envelope $envelope): void
    {
        $this->deliveries[] = [$envelope->letter];
        if ($this->inbox->push(new Delivery($envelope, $this->clock->microtime()), 0.05) !== false) {
            return;
        }

        DeliveryException::inboxFull();
    }

    public function run(Envelope $envelope): ?Turn
    {
        $done = new Channel(1);
        $delivery = new Delivery($envelope, $this->clock->microtime(), $done);
        if ($this->inbox->push($delivery, 0.05) === false) {
            DeliveryException::inboxFull();
        }

        $outcome = $done->pop();
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome;
    }
}
