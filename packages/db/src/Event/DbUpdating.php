<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Dispatched right before UPDATE SQL executes.
 *
 * Log category: <code>switon.db.updating</code>.
 *
 * @see \Switon\Db\Client Typical emitter
 * @see \Switon\Db\Event\DbUpdated
 * @see \Switon\Db\Event\AbstractDbExecuting
 */
#[EventLevel(Severity::INFO)]
class DbUpdating extends AbstractDbExecuting
{
}
