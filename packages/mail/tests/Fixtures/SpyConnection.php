<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Fixtures;

use Switon\Mail\ConnectionInterface;
use Switon\Mail\Delivery;

/**
 * @internal
 */
final class SpyConnection implements ConnectionInterface
{
    public ?Delivery $lastRequest = null;

    public int $sendCount = 0;

    public function send(Delivery $request): void
    {
        $this->lastRequest = $request;
        $this->sendCount++;
    }
}
