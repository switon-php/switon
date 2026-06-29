<?php

declare(strict_types=1);

namespace Switon\Orm\Attribute;

use Attribute;
use Switon\Core\Attribute\Autowired;
use Switon\Orm\Entity;
use Switon\Orm\EntityMetadataInterface;
use Switon\Validating\AbstractConstraint;
use Switon\Validating\Validation;

/**
 * Validation constraint that rejects changes to an already-persisted field.
 *
 * Road-signs:
 * - new entities pass
 * - null values pass
 * - existing rows compare against database value
 *
 * Guidance: Use this only for fields that are truly write-once, because validation performs a database lookup.
 *
 * @see \Switon\Orm\EntityMetadataInterface::getConstraints()
 * @see \Switon\Orm\Attribute\Unique
 * @see \Switon\Orm\Attribute\Exists
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Immutable extends AbstractConstraint
{
    #[Autowired] protected EntityMetadataInterface $entityMetadata;

    /**
     * Validate that the field value has not changed from its database value.
     *
     * @param Validation $validation The validation context containing value and source entity
     *
     * @return bool True if validation passes (value unchanged), false if validation fails or source is not an Entity
     */
    public function validate(Validation $validation): bool
    {
        if ($validation->value === null) {
            return true;
        }

        $entity = $validation->source;
        if (!$entity instanceof Entity) {
            return false;
        }
        $entityClass = $entity::class;

        $primaryKey = $this->entityMetadata->getPrimaryKey($entityClass);
        if (!isset($entity->$primaryKey)) {
            return true;
        }

        $repository = $this->entityMetadata->getRepository($entityClass);
        return $validation->value === $repository->value([$primaryKey => $entity->$primaryKey], $validation->field);
    }
}
