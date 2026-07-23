<?php

declare(strict_types=1);

namespace Switon\Orm\Tests\Fixtures;

use Switon\Orm\EntityMetadataInterface;
use Switon\Orm\Relation\MorphToRelation;

class MorphToRelationProbe extends MorphToRelation
{
    public function resolveEntityClassForTest(string $type): string
    {
        return $this->resolveEntityClass($type);
    }

    public function setSelfEntityClassForTest(string $selfEntityClass): static
    {
        $this->selfEntityClass = $selfEntityClass;

        return $this;
    }

    public function setEntityMetadataForTest(EntityMetadataInterface $entityMetadata): static
    {
        $this->entityMetadata = $entityMetadata;

        return $this;
    }
}
