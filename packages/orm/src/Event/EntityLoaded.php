<?php

declare(strict_types=1);

namespace Switon\Orm\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Orm\Entity;
use Switon\Orm\RepositoryInterface;

/**
 * Dispatched after an entity is hydrated from query rows.
 *
 * Log category: <code>switon.orm.entity.loaded</code>
 *
 * @see \Switon\Orm\AbstractRepository::hydrateEntity() Typical emitter
 * @see \Switon\Orm\Event\EntitiesLoaded
 */
#[EventLevel(Severity::DEBUG)]
class EntityLoaded implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param RepositoryInterface<Entity> $sender Repository that hydrated the entity
     * @param Entity $entity Hydrated entity
     */
    public function __construct(public RepositoryInterface $sender, public Entity $entity)
    {
    }

    /** @return RepositoryInterface<Entity> */
    public function getSender(): RepositoryInterface
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
