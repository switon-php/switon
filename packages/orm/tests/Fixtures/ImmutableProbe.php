<?php

declare(strict_types=1);

namespace Switon\Orm\Tests\Fixtures;

use Switon\Orm\Attribute\Immutable;
use Switon\Orm\EntityMetadataInterface;

class ImmutableProbe extends Immutable
{
    public function setEntityMetadataForTest(EntityMetadataInterface $entityMetadata): static
    {
        $this->entityMetadata = $entityMetadata;

        return $this;
    }
}
