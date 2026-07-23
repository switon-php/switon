<?php

declare(strict_types=1);

namespace Switon\Orm\Tests\Fixtures;

use Switon\Orm\Attribute\Exists;
use Switon\Orm\EntityMetadataInterface;

class ExistsProbe extends Exists
{
    public function setEntityMetadataForTest(EntityMetadataInterface $entityMetadata): static
    {
        $this->entityMetadata = $entityMetadata;

        return $this;
    }
}
