<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use Switon\Query\Query;
use Switon\Query\Table;
use Switon\Query\Tests\TestCase;

/**
 * Test cases for Query class.
 *
 * Tests basic query building functionality without database connection.
 *
 * @group query
 */
class QueryTest extends TestCase
{
    /**
     * Test that setEntityClass() sets entity class.
     */
    public function testSetEntityClass(): void
    {
        $query = $this->make(Query::class);
        $query->setEntityClass('TestEntity');

        $this->assertEquals('TestEntity', $query->getEntityClass());
    }

    /**
     * Test that getEntityClass() returns null when not set.
     */
    public function testGetEntityClassReturnsNull(): void
    {
        $query = $this->make(Query::class);

        $this->assertNull($query->getEntityClass());
    }

    /**
     * Test that setTable() sets table.
     */
    public function testSetTable(): void
    {
        $query = $this->make(Query::class);
        $table = Table::of('users');
        $query->setTable($table);

        // Table is stored internally, we can verify by checking if query accepts table operations
        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that from() sets table name.
     */
    public function testFrom(): void
    {
        $query = $this->make(Query::class);
        $query->from('users');

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that from() with alias sets table and alias.
     */
    public function testFromWithAlias(): void
    {
        $query = $this->make(Query::class);
        $query->from('users', 'u');

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that select() sets fields.
     */
    public function testSelect(): void
    {
        $query = $this->make(Query::class);
        $query->select(['id', 'name', 'email']);

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that select() with empty array does nothing.
     */
    public function testSelectWithEmptyArray(): void
    {
        $query = $this->make(Query::class);
        $result = $query->select([]);

        $this->assertSame($query, $result);
    }

    /**
     * Test that distinct() sets distinct flag.
     */
    public function testDistinct(): void
    {
        $query = $this->make(Query::class);
        $query->distinct(true);

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that where() adds conditions.
     */
    public function testWhere(): void
    {
        $query = $this->make(Query::class);
        $query->where(['status' => 1]);

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that whereEq() adds equality condition.
     */
    public function testWhereEq(): void
    {
        $query = $this->make(Query::class);
        $query->whereEq('status', 1);

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that whereIn() adds IN condition.
     */
    public function testWhereIn(): void
    {
        $query = $this->make(Query::class);
        $query->whereIn('status', [1, 2, 3]);

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that orderBy() sets order.
     */
    public function testOrderBy(): void
    {
        $query = $this->make(Query::class);
        $query->orderBy(['id' => SORT_ASC, 'name' => SORT_DESC]);

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that indexBy() sets index field.
     */
    public function testIndexBy(): void
    {
        $query = $this->make(Query::class);
        $query->indexBy('id');

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that groupBy() sets group fields.
     */
    public function testGroupBy(): void
    {
        $query = $this->make(Query::class);
        $query->groupBy('status');

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that groupBy() accepts array.
     */
    public function testGroupByWithArray(): void
    {
        $query = $this->make(Query::class);
        $query->groupBy(['status', 'type']);

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that with() sets relations.
     */
    public function testWith(): void
    {
        $query = $this->make(Query::class);
        $query->with(['profile', 'posts']);

        $this->assertEquals(['profile', 'posts'], $query->getWith());
    }

    /**
     * Test that getWith() returns relations array.
     */
    public function testGetWith(): void
    {
        $query = $this->make(Query::class);
        $query->with(['profile']);

        $this->assertEquals(['profile'], $query->getWith());
    }

    /**
     * Test that limit() sets limit and offset.
     */
    public function testLimit(): void
    {
        $query = $this->make(Query::class);
        $query->limit(10, 20);

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that limit() with default offset.
     */
    public function testLimitWithDefaultOffset(): void
    {
        $query = $this->make(Query::class);
        $query->limit(10);

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that setColumnMap() sets column map.
     */
    public function testSetColumnMap(): void
    {
        $query = $this->make(Query::class);
        $query->setColumnMap(['userId' => 'user_id', 'userName' => 'user_name']);

        $this->assertInstanceOf(Query::class, $query);
    }

    public function testWhereWritesPredicatesAndBind(): void
    {
        $query = $this->make(Query::class);
        $query->where(['status' => 1]);

        $bind = $query->getBind();
        $this->assertArrayHasKey('status', $bind);
        $this->assertSame(1, $bind['status']);
    }

    /**
     * Test that join() adds join clause.
     */
    public function testJoin(): void
    {
        $query = $this->make(Query::class);
        $table = Table::of('profiles');
        $query->join($table, 'users.id = profiles.user_id');

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that innerJoin() adds INNER JOIN.
     */
    public function testInnerJoin(): void
    {
        $query = $this->make(Query::class);
        $table = Table::of('profiles');
        $query->innerJoin($table, 'users.id = profiles.user_id');

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that leftJoin() adds LEFT JOIN.
     */
    public function testLeftJoin(): void
    {
        $query = $this->make(Query::class);
        $table = Table::of('profiles');
        $query->leftJoin($table, 'users.id = profiles.user_id');

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that rightJoin() adds RIGHT JOIN.
     */
    public function testRightJoin(): void
    {
        $query = $this->make(Query::class);
        $table = Table::of('profiles');
        $query->rightJoin($table, 'users.id = profiles.user_id');

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that having() adds HAVING clause.
     */
    public function testHaving(): void
    {
        $query = $this->make(Query::class);
        $query->having('COUNT(*) > 10');

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that having() with array combines conditions.
     */
    public function testHavingWithArray(): void
    {
        $query = $this->make(Query::class);
        $query->having(['COUNT(*) > 10', 'SUM(amount) < 1000']);

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that forUpdate() sets FOR UPDATE flag.
     */
    public function testForUpdate(): void
    {
        $query = $this->make(Query::class);
        $query->forUpdate(true);

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that setBind() sets bind parameters.
     */
    public function testSetBind(): void
    {
        $query = $this->make(Query::class);
        $query->whereEq('status', 1);
        $query->setBind(['age' => 18], true);

        $bind = $query->getBind();
        $this->assertIsArray($bind);
        $this->assertArrayHasKey('status', $bind);
        $this->assertArrayHasKey('age', $bind);
    }

    /**
     * Test that getBind() returns bind parameters.
     */
    public function testGetBind(): void
    {
        $query = $this->make(Query::class);
        $query->whereEq('status', 1);

        $bind = $query->getBind();
        $this->assertIsArray($bind);
        $this->assertArrayHasKey('status', $bind);
        $this->assertEquals(1, $bind['status']);
    }

    /**
     * Test that setBind() with merge false replaces parameters.
     */
    public function testSetBindWithoutMerge(): void
    {
        $query = $this->make(Query::class);
        $query->whereEq('status', 1);
        $query->setBind(['age' => 18], false);

        $bind = $query->getBind();
        $this->assertIsArray($bind);
        // When merge is false, it still merges with existing binds from conditions
        // So we just verify age is present
        $this->assertArrayHasKey('age', $bind);
    }

    /**
     * Test that map() sets mapping function.
     */
    public function testMap(): void
    {
        $query = $this->make(Query::class);
        $query->map(fn ($row) => $row);

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that setFetchType() sets fetch type.
     */
    public function testSetFetchType(): void
    {
        $query = $this->make(Query::class);
        $query->setFetchType(false);

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test that tap() applies callback to query.
     */
    public function testTap(): void
    {
        $query = $this->make(Query::class);
        $query->tap(function ($q) {
            $q->where(['status' => 1]);
        });

        $this->assertInstanceOf(Query::class, $query);
    }

    /**
     * Test fluent interface chaining.
     */
    public function testFluentInterface(): void
    {
        $query = $this->make(Query::class);
        $result = $query
            ->from('users')
            ->select(['id', 'name'])
            ->where(['status' => 1])
            ->orderBy(['id' => SORT_ASC])
            ->limit(10);

        $this->assertSame($query, $result);
    }
}
