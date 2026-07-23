<?php

declare(strict_types=1);

namespace Switon\Orm\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Switon\Orm\Entity;
use Switon\Orm\EntityMetadataInterface;
use Switon\Orm\Tests\Fixtures\MorphToRelationProbe;
use Switon\Query\QueryInterface;

#[AllowMockObjectsWithoutExpectations]
class MorphToRelationTest extends TestCase
{
    public function testGetRelatedQueryDelegatesToEntityMetadataForSelfPlaceholder(): void
    {
        $entityMetadata = $this->createMock(EntityMetadataInterface::class);
        $query = $this->createMock(QueryInterface::class);

        $entityMetadata->expects($this->once())
            ->method('createQuery')
            ->with(TestSelfEntity::class)
            ->willReturn($query);

        $relation = (new MorphToRelationProbe('morph_table', 'morph_id'))
            ->setSelfEntityClassForTest(TestSelfEntity::class)
            ->setEntityMetadataForTest($entityMetadata);

        $this->assertSame($query, $relation->getRelatedQuery());
    }
}

class TestSelfEntity extends Entity
{
    public ?int $id = null;
}
