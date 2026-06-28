<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Db\ClientInterface;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Dispatched right before UPSERT SQL executes.
 *
 * Log category: <code>switon.db.upserting</code>.
 *
 * @see \Switon\Db\Client Typical emitter
 * @see \Switon\Db\Event\DbUpserted
 */
#[EventLevel(Severity::DEBUG)]
class DbUpserting implements SenderAwareInterface
{
    /**
     * @param array<string, mixed> $insertValues
     * @param array<int|string, mixed> $updateValues
     */
    public function __construct(
        public ClientInterface $sender,
        public string          $table,
        public array           $insertValues,
        public array           $updateValues,
        public string          $primaryKey,
    ) {
    }

    public function getSender(): ClientInterface
    {
        return $this->sender;
    }
}
