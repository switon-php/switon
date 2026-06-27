<?php

declare(strict_types=1);

namespace Switon\Orm\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Orm\Entity;
use Switon\Orm\RelationManagerInterface;

/**
 * Dispatched before loading a relation batch via RelationManager::earlyLoad().
 *
 * Log category: <code>switon.orm.early.loading</code>
 *
 * @see \Switon\Orm\RelationManager::earlyLoad()
 * @see \Switon\Orm\Event\EarlyLoaded
 */
#[EventLevel(Severity::DEBUG)]
class EarlyLoading implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param class-string<Entity> $entityClass
     * @param array<int, Entity> $entities
     */
    public function __construct(
        public RelationManagerInterface $sender,
        public string $entityClass,
        public string $relationName,
        public array  $entities,
    ) {
    }

    public function getSender(): RelationManagerInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'entity' => $this->entityClass,
            'relation' => $this->relationName,
            'mode' => 'early',
        ];
    }
}
