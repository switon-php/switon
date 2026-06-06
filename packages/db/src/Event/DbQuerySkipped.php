<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Dispatched when a query is intentionally skipped (for example WHERE FALSE).
 *
 * Log category: <code>switon.db.query.skipped</code>.
 *
 * @see \Switon\Query\Query::execute() Typical emitter
 * @see \Switon\Db\Event\DbQuerying Related path
 */
#[EventLevel(Severity::DEBUG)]
class DbQuerySkipped
{
    /**
     * @param array<string, mixed> $bind
     */
    public function __construct(
        public string $sql,
        public array  $bind = [],
    ) {

    }
}
