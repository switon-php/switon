<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Db\ClientInterface;

/**
 * Base payload for DB "before execution" SQL events.
 *
 * @see \Switon\Db\ClientInterface
 * @see \Switon\Db\Event\DbExecuting
 */
class AbstractDbExecuting
{
    /**
     * @param array<string, mixed> $bind
     */
    public function __construct(
        public ClientInterface $client,
        public string          $sql,
        public array           $bind,
    ) {
    }
}
