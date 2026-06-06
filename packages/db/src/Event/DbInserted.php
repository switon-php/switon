<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Dispatched after INSERT SQL completes.
 *
 * Log category: <code>switon.db.inserted</code>.
 *
 * @see \Switon\Db\Client Typical emitter
 * @see \Switon\Db\Event\DbInserting
 * @see \Switon\Db\Event\AbstractDbExecuted
 */
#[EventLevel(Severity::DEBUG)]
class DbInserted extends AbstractDbExecuted
{
}
