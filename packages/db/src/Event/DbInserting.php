<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Dispatched right before INSERT SQL executes.
 *
 * Log category: <code>switon.db.inserting</code>.
 *
 * @see \Switon\Db\Client Typical emitter
 * @see \Switon\Db\Event\DbInserted
 * @see \Switon\Db\Event\AbstractDbExecuting
 */
#[EventLevel(Severity::INFO)]
class DbInserting extends AbstractDbExecuting
{
}
