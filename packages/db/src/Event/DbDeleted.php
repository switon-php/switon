<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Dispatched after DELETE SQL completes.
 *
 * Log category: <code>switon.db.deleted</code>.
 *
 * @see \Switon\Db\Client Typical emitter
 * @see \Switon\Db\Event\DbDeleting
 * @see \Switon\Db\Event\AbstractDbExecuted
 */
#[EventLevel(Severity::DEBUG)]
class DbDeleted extends AbstractDbExecuted
{
}
