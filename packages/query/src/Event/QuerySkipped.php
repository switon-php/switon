<?php

declare(strict_types=1);

namespace Switon\Query\Event;

use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Query\QueryInterface;

/**
 * Dispatched when a query is intentionally skipped, such as WHERE FALSE.
 *
 * Log category: <code>switon.query.skipped</code>.
 *
 * @see \Switon\Query\Query::execute() Typical emitter
 *
 * @template Model
 */
#[EventLevel(Severity::DEBUG)]
class QuerySkipped implements SenderAwareInterface
{
    /**
     * @param QueryInterface<Model> $sender Query that was skipped
     * @param array<string, mixed> $bind
     */
    public function __construct(
        public QueryInterface $sender,
        public string $sql,
        public array $bind = [],
    ) {
    }

    /** @return QueryInterface<Model> */
    public function getSender(): QueryInterface
    {
        return $this->sender;
    }
}
