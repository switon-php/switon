<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Fixtures;

use Switon\Mail\Connection;
use Switon\Mail\Delivery;

/**
 * @internal
 */
final class ConnectionProbe extends Connection
{
    public function handleObjectId(): int
    {
        return spl_object_id($this->prepareHandle());
    }

    public function urlForProbe(Delivery $request): string
    {
        return $this->urlFor($request);
    }
}
