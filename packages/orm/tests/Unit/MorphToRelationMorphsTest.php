<?php

declare(strict_types=1);

namespace Switon\Orm\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Core\Exception\RuntimeException;
use Switon\Orm\EntityMetadataInterface;
use Switon\Orm\Tests\Fixtures\MorphToRelationProbe;
use Switon\Orm\Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
class MorphToRelationMorphsTest extends TestCase
{
    public function testResolveUsesExplicitMapping(): void
    {
        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->expects($this->never())->method('getTable');

        $relation = $this->makeMorphToRelation(
            'type_field',
            'id_field',
            $metadata,
            ['posts' => 'App\\Entity\\Post'],
            MorphToRelationProbe::class,
        );

        $this->assertSame('App\\Entity\\Post', $relation->resolveEntityClassForTest('posts'));
    }

    public function testResolveSupportsNumericEntityEntries(): void
    {
        $entityClass = 'App\\Entity\\Post';

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getTable')
            ->with($entityClass, true)
            ->willReturn('posts');

        $relation = $this->makeMorphToRelation(
            'type_field',
            'id_field',
            $metadata,
            [$entityClass],
            MorphToRelationProbe::class,
        );

        $this->assertSame($entityClass, $relation->resolveEntityClassForTest('posts'));
    }

    public function testResolveNormalizesSchemaQualifiedTable(): void
    {
        $metadata = $this->createStub(EntityMetadataInterface::class);

        $relation = $this->makeMorphToRelation(
            'type_field',
            'id_field',
            $metadata,
            ['posts' => 'App\\Entity\\Post'],
            MorphToRelationProbe::class,
        );

        $this->assertSame('App\\Entity\\Post', $relation->resolveEntityClassForTest('schema.posts'));
    }

    public function testResolveNormalizesShardingSuffix(): void
    {
        $metadata = $this->createStub(EntityMetadataInterface::class);

        $relation = $this->makeMorphToRelation(
            'type_field',
            'id_field',
            $metadata,
            ['posts' => 'App\\Entity\\Post'],
            MorphToRelationProbe::class,
        );

        $this->assertSame('App\\Entity\\Post', $relation->resolveEntityClassForTest('posts:post_id%8'));
    }

    public function testResolveThrowsWhenNotConfigured(): void
    {
        $metadata = $this->createStub(EntityMetadataInterface::class);

        $relation = $this->makeMorphToRelation(
            'type_field',
            'id_field',
            $metadata,
            [],
            MorphToRelationProbe::class,
        );

        $this->expectException(RuntimeException::class);

        $relation->resolveEntityClassForTest('posts');
    }
}
