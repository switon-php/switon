<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Dispatched right before DELETE SQL executes.
 *
 * Log category: <code>switon.db.deleting</code>.
 *
 * @see \Switon\Db\Client Typical emitter
 * @see \Switon\Db\Event\DbDeleted
 * @see \Switon\Db\Event\AbstractDbExecuting
 */
#[EventLevel(Severity::INFO)]
class DbDeleting extends AbstractDbExecuting
{
}
