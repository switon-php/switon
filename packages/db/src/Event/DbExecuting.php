<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Db\ClientInterface;
use Switon\Core\SenderAwareInterface;
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
class DbExecuting implements SenderAwareInterface
{
    public ClientInterface $sender;

    /**
     * @param array<string, mixed> $bind
     */
    public function __construct(
        ClientInterface $sender,
        public string $event,
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
