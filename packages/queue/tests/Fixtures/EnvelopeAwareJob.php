<?php

declare(strict_types=1);

namespace Switon\Queue\Tests\Fixtures;

use Switon\Core\RunnerInterface;
use Switon\Queue\Attribute\Queued;
use Switon\Queue\QueueEnvelope;

#[Queued('envelopes')]
class EnvelopeAwareJob implements RunnerInterface
{
    public static ?QueueEnvelope $payload = null;

    public function run(mixed $payload): mixed
    {
        self::$payload = $payload instanceof QueueEnvelope ? $payload : null;

        return null;
    }
}
