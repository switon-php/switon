<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Db\ClientInterface;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Dispatched after UPSERT SQL completes.
 *
 * Log category: <code>switon.db.upserted</code>.
 *
 * @see \Switon\Db\Client Typical emitter
 * @see \Switon\Db\Event\DbUpserting
 */
#[EventLevel(Severity::DEBUG)]
class DbUpserted implements SenderAwareInterface
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
        public int             $count,
        public float           $elapsed,
    ) {
    }

    public function getSender(): ClientInterface
    {
        return $this->sender;
    }
}
