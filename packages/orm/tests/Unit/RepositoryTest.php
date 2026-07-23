<?php

declare(strict_types=1);

namespace Switon\Orm\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Orm\EntityManagerInterface;
use Switon\Orm\QueryBuilderInterface;
use Switon\Orm\Repository;
use Switon\Orm\Tests\Fixtures\TestEntity;
use Switon\Orm\Tests\TestCase;

class RepositoryTestStub extends Repository
{
    protected string $entityClass = TestEntity::class;

    public function exposeEntityManager(): EntityManagerInterface
    {
        return $this->getEntityManager();
    }

    public function exposeQueryBuilder(): QueryBuilderInterface
    {
        return $this->getQueryBuilder();
    }
}

#[AllowMockObjectsWithoutExpectations]
class RepositoryTest extends TestCase
{
    public function testGetEntityManagerReturnsAutowiredInstance(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $this->container->set(EntityManagerInterface::class, $entityManager);

        $repository = $this->make(RepositoryTestStub::class);

        $this->assertSame($entityManager, $repository->exposeEntityManager());
    }

    public function testGetQueryBuilderReturnsAutowiredInstance(): void
    {
        $queryBuilder = $this->createMock(QueryBuilderInterface::class);
        $this->container->set(QueryBuilderInterface::class, $queryBuilder);

        $repository = $this->make(RepositoryTestStub::class);

        $this->assertSame($queryBuilder, $repository->exposeQueryBuilder());
    }
}
