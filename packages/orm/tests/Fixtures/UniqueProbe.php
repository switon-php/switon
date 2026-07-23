<?php

declare(strict_types=1);

namespace Switon\Orm\Tests\Fixtures;

use Switon\Orm\Attribute\Unique;
use Switon\Orm\EntityMetadataInterface;

class UniqueProbe extends Unique
{
    public function setEntityMetadataForTest(EntityMetadataInterface $entityMetadata): static
    {
        $this->entityMetadata = $entityMetadata;

        return $this;
    }
}
