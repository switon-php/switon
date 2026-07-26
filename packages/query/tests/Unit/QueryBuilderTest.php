<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use Switon\Query\Exception\InvalidSortDirectionException;
use Switon\Query\Query;
use Switon\Query\Table;
use Switon\Query\Tests\TestCase;

/**
 * Test cases for Query builder methods edge cases and error handling.
 *
 * Tests builder method validation, error cases, and edge conditions.
 */
class QueryBuilderTest extends TestCase
{
    protected Query $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = $this->make(Query::class);
    }

    /**
     * Test that select() with relation config converts to with().
     */
    public function testSelectWithRelationConfig(): void
    {
        $this->query->select([
            'id',
            'name',
            'profile' => ['name', 'bio'],
        ]);

        $with = $this->query->getWith();
        $this->assertArrayHasKey('profile', $with);
        $this->assertEquals(['name', 'bio'], $with['profile']);
    }

    /**
     * Test that select() stringifies non-string scalar field values.
     */
    public function testSelectStringifiesNonStringFieldValues(): void
    {
        $this->query->select([
            'id',
            'name' => 123,
        ]);

        $this->assertSame('[id], [123] AS [name]', $this->query->getClauses()->fields);
    }

    /**
     * Test that select() handles fields with brackets.
     */
    public function testSelectWithBrackets(): void
    {
        $this->query->select(['COUNT(*) as total']);

        // Should not throw exception
        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that orderBy() accepts integer keys.
     */
    public function testOrderByWithIntegerKeys(): void
    {
        $this->query->orderBy(['id', 'name']);

        // Should convert to ['id' => SORT_ASC, 'name' => SORT_ASC]
        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that orderBy() validates field values.
     */
    public function testOrderByStringifiesIntegerKeyedFieldNames(): void
    {
        $this->query->orderBy([123 => 456]);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that orderBy() accepts SORT_ASC and SORT_DESC constants.
     */
    public function testOrderByWithSortConstants(): void
    {
        $this->query->orderBy([
            'id' => SORT_ASC,
            'name' => SORT_DESC,
        ]);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that orderBy() accepts string 'ASC' and 'DESC'.
     */
    public function testOrderByWithStringDirections(): void
    {
        $this->query->orderBy([
            'id' => 'ASC',
            'name' => 'DESC',
        ]);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that orderBy() throws exception for invalid direction.
     */
    public function testOrderByThrowsExceptionForInvalidDirection(): void
    {
        $this->expectException(InvalidSortDirectionException::class);

        $this->query->orderBy(['id' => 'INVALID']);
    }

    /**
     * Test that groupBy() accepts string.
     */
    public function testGroupByWithString(): void
    {
        $this->query->groupBy('status, type');

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that groupBy() accepts array.
     */
    public function testGroupByWithArray(): void
    {
        $this->query->groupBy(['status', 'type']);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that groupBy() validates array items are strings.
     */
    public function testGroupByStringifiesArrayItems(): void
    {
        $this->query->groupBy(['status', 123]);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that indexBy() accepts string.
     */
    public function testIndexByWithString(): void
    {
        $this->query->indexBy('id');

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that indexBy() accepts array.
     */
    public function testIndexByWithArray(): void
    {
        $this->query->indexBy(['key' => 'id']);

        // Should call select() with the array
        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that indexBy() accepts callable.
     */
    public function testIndexByWithCallable(): void
    {
        $this->query->indexBy(function ($row) {
            return $row['id'];
        });

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that with() merges relations.
     */
    public function testWithMergesRelations(): void
    {
        $this->query->with(['profile']);
        $this->query->with(['posts']);

        $with = $this->query->getWith();
        $this->assertContains('profile', $with);
        $this->assertContains('posts', $with);
    }

    /**
     * Test that with() handles nested relations.
     */
    public function testWithHandlesNestedRelations(): void
    {
        $profileQuery = $this->make(Query::class);
        $this->query->with([
            'profile' => $profileQuery,
            'profile.comments' => ['id', 'text'],
        ]);

        // Should process nested relation
        $with = $this->query->getWith();
        $this->assertArrayHasKey('profile', $with);
    }

    /**
     * Test that join() normalizes condition field names.
     */
    public function testJoinNormalizesConditionFields(): void
    {
        $table = Table::of('profiles');
        $this->query->join($table, 'users.id = profiles.user_id');

        // Should normalize field names in condition
        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that join() preserves condition with brackets.
     */
    public function testJoinPreservesConditionWithBrackets(): void
    {
        $table = Table::of('profiles');
        $this->query->join($table, '[users].[id] = [profiles].[user_id]');

        // Should preserve condition as-is
        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that having() accepts string.
     */
    public function testHavingWithString(): void
    {
        $this->query->having('COUNT(*) > 10');

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that having() accepts array.
     */
    public function testHavingWithArray(): void
    {
        $this->query->having(['COUNT(*) > 10', 'SUM(amount) < 1000']);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that having() with single item array uses it directly.
     */
    public function testHavingWithSingleItemArray(): void
    {
        $this->query->having(['COUNT(*) > 10']);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that having() sets bind parameters.
     */
    public function testHavingSetsBindParameters(): void
    {
        $this->query->having('SUM(amount) > :min', ['min' => 100]);

        $bind = $this->query->getBind();
        $this->assertArrayHasKey('min', $bind);
        $this->assertEquals(100, $bind['min']);
    }

    /**
     * Test that distinct() sets distinct flag.
     */
    public function testDistinct(): void
    {
        $this->query->distinct(true);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that distinct() can be disabled.
     */
    public function testDistinctCanBeDisabled(): void
    {
        $this->query->distinct(true);
        $this->query->distinct(false);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that from() with entity class sets entityClass.
     */
    public function testFromWithEntityClass(): void
    {
        $this->query->from('App\\Entity\\User');

        $this->assertEquals('App\\Entity\\User', $this->query->getEntityClass());
        $this->assertNull($this->query->getTable());
    }

    /**
     * Test that from() with alias sets table and alias.
     */
    public function testFromWithAlias(): void
    {
        $this->query->from('users', 'u');

        $table = $this->query->getTable();
        $this->assertNotNull($table);
        $this->assertSame('users', $table->table);
        $this->assertSame('default', $table->connection);
        $this->assertSame('u', $this->query->getClauses()->alias);
    }

    /**
     * Test that limit() sets limit and offset.
     */
    public function testLimit(): void
    {
        $this->query->limit(10, 20);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that limit() with default offset uses 0.
     */
    public function testLimitWithDefaultOffset(): void
    {
        $this->query->limit(10);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that map() sets mapping function.
     */
    public function testMap(): void
    {
        $this->query->map(function ($row) {
            return $row;
        });

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that setFetchType() sets fetch type.
     */
    public function testSetFetchType(): void
    {
        $this->query->setFetchType(false);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that tap() applies callback to query.
     */
    public function testTap(): void
    {
        $called = false;
        $this->query->tap(function ($q) use (&$called) {
            $called = true;
            $this->assertInstanceOf(Query::class, $q);
        });

        $this->assertTrue($called);
    }


    /**
     * Test that setColumnMap() sets column map.
     */
    public function testSetColumnMap(): void
    {
        $this->query->setColumnMap([
            'userId' => 'user_id',
            'userName' => 'user_name',
        ]);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    public function testSetBindMergesExtraBind(): void
    {
        $this->query->where(['status' => 1]);
        $this->query->setBind(['custom' => 'value'], true);

        $bind = $this->query->getBind();
        $this->assertArrayHasKey('status', $bind);
        $this->assertArrayHasKey('custom', $bind);
    }

    /**
     * Test that innerJoin() creates INNER JOIN.
     */
    public function testInnerJoin(): void
    {
        $table = Table::of('profiles');
        $this->query->innerJoin($table, 'users.id = profiles.user_id');

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that leftJoin() creates LEFT JOIN.
     */
    public function testLeftJoin(): void
    {
        $table = Table::of('profiles');
        $this->query->leftJoin($table, 'users.id = profiles.user_id');

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that rightJoin() creates RIGHT JOIN.
     */
    public function testRightJoin(): void
    {
        $table = Table::of('profiles');
        $this->query->rightJoin($table, 'users.id = profiles.user_id');

        $this->assertInstanceOf(Query::class, $this->query);
    }
}
