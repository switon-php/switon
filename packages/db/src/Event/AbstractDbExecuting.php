<?php

declare(strict_types=1);

namespace Switon\Db\Event;

use Switon\Db\ClientInterface;
use Switon\Core\SenderAwareInterface;

/**
 * Base payload for DB "before execution" SQL events.
 *
 * @see \Switon\Db\ClientInterface
 * @see \Switon\Db\Event\DbExecuting
 */
class AbstractDbExecuting implements SenderAwareInterface
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
