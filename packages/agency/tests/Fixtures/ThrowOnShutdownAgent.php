<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Fixtures;

use Switon\Agency\Tests\Fixtures\UnitTestAgent;
use Switon\Agency\Exception;

/** Test double: first {@see shutdown()} throws; later calls delegate so test teardown can stop workers. */
class ThrowOnShutdownAgent extends UnitTestAgent
{
    protected bool $throwOnShutdown = true;

    public function shutdown(): void
    {
        if ($this->throwOnShutdown) {
            $this->throwOnShutdown = false;
            try {
                Exception::raise('shutdown failed');
            } finally {
                parent::shutdown();
            }

            return;
        }

        parent::shutdown();
    }
}
