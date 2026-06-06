<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Dispatched after UPDATE SQL completes.
 *
 * Log category: <code>switon.db.updated</code>.
 *
 * @see \Switon\Db\Client Typical emitter
 * @see \Switon\Db\Event\DbUpdating
 * @see \Switon\Db\Event\AbstractDbExecuted
 */
#[EventLevel(Severity::DEBUG)]
class DbUpdated extends AbstractDbExecuted
{
}
