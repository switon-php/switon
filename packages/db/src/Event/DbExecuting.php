<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Db\ClientInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Generic SQL pre-execution event with operation class and bind values.
 *
 * Log category: <code>switon.db.executing</code>.
 *
 * @see \Switon\Db\Client
 * @see \Switon\Db\Event\DbExecuted
 */
#[EventLevel(Severity::DEBUG)]
class DbExecuting
{
    /**
     * @param array<string, mixed> $bind
     */
    public function __construct(
        public ClientInterface $client,
        public string          $event,
        public string          $sql,
        public array           $bind,
    ) {
    }
}
