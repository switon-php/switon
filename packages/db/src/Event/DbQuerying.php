<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Db\ClientInterface;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Dispatched right before SELECT SQL executes.
 *
 * Log category: <code>switon.db.querying</code>.
 *
 * @see \Switon\Db\Client Typical emitter
 * @see \Switon\Db\Event\DbQueried
 * @see \Switon\Db\Inspector\SqlCollector Typical consumer
 */
#[EventLevel(Severity::DEBUG)]
class DbQuerying implements SenderAwareInterface
{
    public ClientInterface $sender;

    /**
     * @param array<string, mixed> $bind
     */
    public function __construct(
        ClientInterface $sender,
        public string $sql,
        public array $bind,
    ) {
        $this->sender = $sender;
    }

    public function getSender(): ClientInterface
    {
        return $this->sender;
    }
}
