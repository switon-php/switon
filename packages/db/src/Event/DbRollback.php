<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Db\ClientInterface;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * DB transaction rollback event.
 *
 * Log category: <code>switon.db.rollback</code>.
 *
 * @see \Switon\Db\Client Typical emitter
 * @see \Switon\Db\Event\DbBegin
 * @see \Switon\Db\Event\DbCommit
 * @see \Switon\Db\Inspector\SqlCollector Typical consumer
 */
#[EventLevel(Severity::INFO)]
class DbRollback implements SenderAwareInterface
{
    public ClientInterface $sender;

    public function __construct(ClientInterface $sender) {
        $this->sender = $sender;
    }

    public function getSender(): ClientInterface
    {
        return $this->sender;
    }
}
