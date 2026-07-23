<?php

declare(strict_types=1);

namespace Switon\Orm\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Orm\Entity;
use Switon\Orm\EntityManagerInterface;

/**
 * Dispatched when an update is skipped because no persistent fields changed.
 *
 * Log category: <code>switon.orm.entity.unchanged</code>
 *
 * @see \Switon\Orm\EntityManager::update() Typical emitter
 */
#[EventLevel(Severity::DEBUG)]
class EntityUnchanged implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param EntityManagerInterface<Entity> $sender
     */
    public function __construct(
        public EntityManagerInterface $sender,
        public Entity $entity,
        public Entity $original,
    ) {
    }

    /** @return EntityManagerInterface<Entity> */
    public function getSender(): EntityManagerInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'entity' => $this->entity::class,
        ];
    }
}
