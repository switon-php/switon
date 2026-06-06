<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use Switon\Query\Query;
use Switon\Query\QueryBuilderInterface;
use Switon\Query\Tests\TestCase;

/**
 * Tests for factory-style query creation.
 *
 * @group query
 */
class QueryFactoryTest extends TestCase
{
    public function testFromReturnsFreshInstanceEachTime(): void
    {
        $builder = $this->make(QueryBuilderInterface::class);

        $first = $builder->from('users')->where(['status' => 1]);
        $second = $builder->from('users');

        $this->assertNotSame($first, $second);
        $this->assertArrayHasKey('status', $first->getBind());
        $this->assertSame([], $second->getBind());
    }

    public function testFromCreatesQueryWithTableAndAlias(): void
    {
        $builder = $this->make(QueryBuilderInterface::class);

        $query = $builder->from('users', 'u');

        $this->assertInstanceOf(Query::class, $query);
        $this->assertSame('users', $query->getTable()?->table);
        $this->assertSame('default', $query->getTable()?->connection);
        $this->assertSame('u', $query->getClauses()->alias);
    }

    public function testFromAutoDetectsEntityClassHint(): void
    {
        $builder = $this->make(QueryBuilderInterface::class);

        $query = $builder->from('App\\Entity\\User');

        $this->assertInstanceOf(Query::class, $query);
        $this->assertSame('App\\Entity\\User', $query->getEntityClass());
        $this->assertNull($query->getTable());
    }
}
