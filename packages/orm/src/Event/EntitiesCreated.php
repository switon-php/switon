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
 * Dispatched after a bulk entity create operation.
 *
 * Log category: <code>switon.orm.entities.created</code>
 *
 * @see \Switon\Orm\EntityManager::createMany() Typical emitter
 * @see \Switon\Orm\Event\EntitiesCreating
 * @see \Switon\Orm\Event\EntityCreated Per-entity event
 */
#[EventLevel(Severity::DEBUG)]
class EntitiesCreated implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param EntityManagerInterface<Entity> $sender
     * @param class-string<Entity> $entityClass
     * @param array<int, Entity> $entities
     */
    public function __construct(
        public EntityManagerInterface $sender,
        public string $entityClass,
        public array  $entities,
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
            'entity' => $this->entityClass,
            'count' => count($this->entities),
        ];
    }
}
