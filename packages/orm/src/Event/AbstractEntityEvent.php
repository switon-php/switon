<?php

declare(strict_types=1);

namespace Switon\Orm\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Orm\Entity;
use Switon\Orm\EntityManagerInterface;

/**
 * Base class for ORM entity lifecycle events.
 *
 * Use when an event needs both current entity data and optional original data
 * for change detection.
 *
 * @see \Switon\Orm\Event\EntityEventInterface
 * @see \Switon\Orm\AbstractEntityManager::dispatchEvent() Typical emitter
 * @see \Switon\Orm\Event\EntityCreating
 * @see \Switon\Orm\Event\EntityCreated
 * @see \Switon\Orm\Event\EntityUpdating
 * @see \Switon\Orm\Event\EntityUpdated
 * @see \Switon\Orm\Event\EntityDeleting
 * @see \Switon\Orm\Event\EntityDeleted
 */
class AbstractEntityEvent implements EntityEventInterface, JsonSerializable, SenderAwareInterface
{
    /** @var EntityManagerInterface<Entity> */
    public EntityManagerInterface $sender;
    /**
     * @param EntityManagerInterface<Entity> $sender
     * @param Entity $entity Current entity state
     * @param Entity|null $original Original entity state (available for update/delete events)
     */
    public function __construct(EntityManagerInterface $sender, protected Entity $entity, protected ?Entity $original = null)
    {
        $this->sender = $sender;
    }

    /**
     * {@inheritDoc}
     */
    public function getEntity(): Entity
    {
        return $this->entity;
    }

    /**
     * {@inheritDoc}
     */
    public function getOriginal(): ?Entity
    {
        return $this->original;
    }

    /**
     * {@inheritDoc}
     */
    public function hasChanged(array $fields): bool
    {
        if ($this->original === null) {
            return false;
        }

        $entity = $this->entity;
        $original = $this->original;
        foreach ($fields as $field) {
            if (isset($entity->$field) && $entity->$field !== $original->$field) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['entity' => $this->entity::class, 'fields' => $this->entity->toArray()];
    }

    /** @return EntityManagerInterface<Entity> */
    public function getSender(): EntityManagerInterface
    {
        return $this->sender;
    }
}
