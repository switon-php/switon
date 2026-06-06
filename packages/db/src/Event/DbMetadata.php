<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Db\ClientInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Dispatched after table metadata is loaded from the database.
 *
 * Log category: <code>switon.db.metadata</code>.
 *
 * @see \Switon\Db\Client Typical emitter
 * @see \Switon\Db\TableScannerInterface Typical consumer
 */
#[EventLevel(Severity::DEBUG)]
class DbMetadata
{
    /** @param array<int, mixed> $meta */
    public function __construct(
        public ClientInterface $client,
        public string          $table,
        public array           $meta,
        public float           $elapsed,
    ) {

    }
}
