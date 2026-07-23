<?php

declare(strict_types=1);

namespace Switon\Orm\Tests\Fixtures;

use Switon\Orm\Entity;
use Switon\Orm\EntityManager;
use Switon\Orm\Event\EntityEventInterface;

class EntityManagerInternalsProbe extends EntityManager
{
    /**
     * @param list<string> $fields
     */
    public function validateForTest(Entity $entity, array $fields): void
    {
        $this->validate($entity, $fields);
    }

    public function dispatchEventForTest(EntityEventInterface $event): void
    {
        $this->dispatchEvent($event);
    }
    public function describeEntityTypeForTest(mixed $entity): string
    {
        return $this->describeEntityType($entity);
    }

    /**
     * @param list<string> $fields
     * @param array<string, mixed> $original
     * @param array<string, mixed> $current
     *
     * @return list<string>
     */
    public function getChangedFieldsForTest(array $fields, array $original, array $current): array
    {
        return $this->getChangedFields($fields, $original, $current);
    }
}
