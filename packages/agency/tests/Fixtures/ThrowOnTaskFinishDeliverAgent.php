<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Fixtures;

use Switon\Agency\Exception;
use Switon\Agency\Thread\TaskFinishSignal;
use Switon\Ai\Envelope;

/** Parent double: {@see deliver()} throws on task_finish so finishTask unregister paths can be tested. */
class ThrowOnTaskFinishDeliverAgent extends RecordingRootAgent
{
    public function deliver(Envelope $envelope): void
    {
        if ($envelope->letter instanceof TaskFinishSignal) {
            Exception::raise('Parent inbox rejected task_finish.');
        }

        parent::deliver($envelope);
    }
}
