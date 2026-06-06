<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use JsonSerializable;
use Switon\Db\ClientInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Dispatched after SELECT SQL completes.
 *
 * Log category: <code>switon.db.queried</code>.
 *
 * @see \Switon\Db\Client Typical emitter
 * @see \Switon\Db\Event\DbQuerying
 * @see \Switon\Db\Inspector\SqlCollector Typical consumer
 */
#[EventLevel(Severity::DEBUG)]
class DbQueried implements JsonSerializable
{
    /**
     * @param array<string, mixed> $bind
     */
    public function __construct(
        public ClientInterface $client,
        public string          $sql,
        public array           $bind,
        public int             $count,
        public mixed           $result,
        public float           $elapsed,
    ) {

    }

    /** Serializes event data and truncates large result sets for log safety. */
    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = [
            'sql' => $this->sql,
            'bind' => $this->bind,
            'count' => $this->count,
            'elapsed' => $this->elapsed,
        ];

        // Limit result data for performance in logging
        if (isset($this->result)) {
            if (is_array($this->result) && count($this->result) > 5) {
                $data['result'] = array_slice($this->result, 0, 5);
                $data['result_truncated'] = true;
                $data['result_original_count'] = count($this->result);
            } else {
                $data['result'] = $this->result;
            }
        }

        return $data;
    }
}
