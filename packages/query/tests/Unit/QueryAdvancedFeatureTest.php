<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use Switon\Query\Query;
use Switon\Query\Table;
use Switon\Query\Tests\TestCase;

/**
 * Advanced feature test cases for Query class.
 *
 * Tests advanced query functionality that doesn't require execution.
 */
class QueryAdvancedFeatureTest extends TestCase
{
    protected Query $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = $this->make(Query::class);
        // Set a default table to avoid shard resolution errors in some methods
        $this->query->setTable(Table::of('test_table'));
    }

    /**
     * Test that getTable() returns the set table.
     */
    public function testGetTableReturnsSetTable(): void
    {
        $table = Table::of('users');
        $this->query->setTable($table);

        $result = $this->query->getTable();
        $this->assertSame($table, $result);
    }

    /**
     * Test that multiple with() calls merge properly with specific relation configurations.
     */
    public function testMultipleWithCallsMergeWithConfigurations(): void
    {
        $this->query->with(['profile' => ['name', 'email']]);
        $this->query->with(['posts' => ['title', 'content']]);

        $with = $this->query->getWith();
        $this->assertArrayHasKey('profile', $with);
        $this->assertArrayHasKey('posts', $with);
        $this->assertEquals(['name', 'email'], $with['profile']);
        $this->assertEquals(['title', 'content'], $with['posts']);
    }

    /**
     * Test that select() with complex field configurations works.
     */
    public function testSelectWithComplexFieldConfigurations(): void
    {
        $this->query->select([
            'id',
            'name',
            'full_name' => 'CONCAT(first_name, \" \", last_name)',
            'status'
        ]);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that select() with relation configuration properly sets with().
     */
    public function testSelectWithRelationConfigurationSetsWith(): void
    {
        $this->query->select([
            'id',
            'name',
            'profile' => ['name', 'email'],
        ]);

        $with = $this->query->getWith();
        $this->assertArrayHasKey('profile', $with);
        $this->assertEquals(['name', 'email'], $with['profile']);
    }

    /**
     * Test that indexBy() with callable works properly.
     */
    public function testIndexByWithCallable(): void
    {
        $this->query->indexBy(function ($row) {
            return $row['id'] ?? 'default';
        });

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that indexBy() with array calls select properly.
     */
    public function testIndexByWithArrayCallsSelect(): void
    {
        $this->query->indexBy(['key' => 'id']);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that where() with complex conditions works.
     */
    public function testWhereWithComplexConditions(): void
    {
        $this->query->where([
            'status' => 1,
            'name' => 'John',
            'age>' => 18,
            'city' => ['New York', 'London'], // IN condition
            'email*=' => 'gmail', // LIKE %gmail%
            'score~=' => [80, 100], // BETWEEN
        ]);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that where() with raw expressions works.
     */
    public function testWhereWithRawExpressions(): void
    {
        $this->query->where([
            0 => 'field1 = :value1 AND field2 > :value2',
            'status' => 1
        ]);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that distinct() can be enabled and disabled.
     */
    public function testDistinctCanBeEnabledAndDisabled(): void
    {
        $result1 = $this->query->distinct(true);
        $this->assertInstanceOf(Query::class, $result1);
        $this->assertSame($this->query, $result1);

        $result2 = $this->query->distinct(false);
        $this->assertInstanceOf(Query::class, $result2);
        $this->assertSame($this->query, $result2);
    }

    /**
     * Test that forUpdate() can be enabled and disabled.
     */
    public function testForUpdateCanBeEnabledAndDisabled(): void
    {
        $result1 = $this->query->forUpdate(true);
        $this->assertInstanceOf(Query::class, $result1);
        $this->assertSame($this->query, $result1);

        $result2 = $this->query->forUpdate(false);
        $this->assertInstanceOf(Query::class, $result2);
        $this->assertSame($this->query, $result2);
    }

    /**
     * Test that from() with entity class sets entity class properly.
     */
    public function testFromWithEntityClass(): void
    {
        $this->query->from('App\\Entity\\User');

        $this->assertEquals('App\\Entity\\User', $this->query->getEntityClass());
    }

    /**
     * Test that select() with empty fields does not change the query structure.
     */
    public function testSelectWithEmptyFields(): void
    {
        $result = $this->query->select([]);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that getBind() returns an array and can retrieve specific values.
     */
    public function testGetBindReturnsArrayAndSpecificValues(): void
    {
        $this->query->whereEq('status', 1);
        $allBinds = $this->query->getBind();

        $this->assertIsArray($allBinds);
        $this->assertArrayHasKey('status', $allBinds);
        $this->assertEquals(1, $allBinds['status']);

        $slice = $this->query->getBind('status');
        $this->assertSame(['status' => 1], $slice);
        $this->assertNull($this->query->getBind('missing')['missing']);
    }

    /**
     * Test that setColumnMap() with empty array works.
     */
    public function testSetColumnMapWithEmptyArray(): void
    {
        $result = $this->query->setColumnMap([]);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that setColumnMap() with field mappings works.
     */
    public function testSetColumnMapWithFieldMappings(): void
    {
        $columnMap = [
            'userId' => 'user_id',
            'userName' => 'user_name',
            'createdAt' => 'created_at'
        ];
        $result = $this->query->setColumnMap($columnMap);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that join() with various parameters works.
     */
    public function testJoinWithVariousParameters(): void
    {
        $table = Table::of('profiles');

        // Test with condition and alias
        $result = $this->query->join($table, 'users.id = profiles.user_id', 'p');

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that join() with null parameters works.
     */
    public function testJoinWithNullParameters(): void
    {
        $table = Table::of('profiles');
        $result = $this->query->join($table);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that different join types work properly.
     */
    public function testDifferentJoinTypes(): void
    {
        $table = Table::of('profiles');

        $innerResult = $this->query->innerJoin($table, 'users.id = profiles.user_id');
        $this->assertInstanceOf(Query::class, $innerResult);

        $leftResult = $this->query->leftJoin($table, 'users.id = profiles.user_id');
        $this->assertInstanceOf(Query::class, $leftResult);

        $rightResult = $this->query->rightJoin($table, 'users.id = profiles.user_id');
        $this->assertInstanceOf(Query::class, $rightResult);
    }

    /**
     * Test that having() with various parameters works.
     */
    public function testHavingWithVariousParameters(): void
    {
        $result = $this->query->having('COUNT(*) > 10');
        $this->assertInstanceOf(Query::class, $result);

        $result2 = $this->query->having(['COUNT(*) > 10', 'SUM(amount) < 1000']);
        $this->assertInstanceOf(Query::class, $result2);

        $result3 = $this->query->having('SUM(amount) > :min', ['min' => 100]);
        $this->assertInstanceOf(Query::class, $result3);
    }

    /**
     * Test that orderBy() with mixed directions works.
     */
    public function testOrderByWithMixedDirections(): void
    {
        $result = $this->query->orderBy([
            'id' => SORT_ASC,
            'name' => 'DESC',
            'created_at' => 'asc',
            'updated_at' => 'desc'
        ]);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that groupBy() with string works.
     */
    public function testGroupByWithString(): void
    {
        $result = $this->query->groupBy('status, type');

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that groupBy() with array works.
     */
    public function testGroupByWithArray(): void
    {
        $result = $this->query->groupBy(['status', 'type']);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that limit() with zero values works.
     */
    public function testLimitWithZeroValues(): void
    {
        $result = $this->query->limit(0, 0);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that map() with complex function works.
     */
    public function testMapWithComplexFunction(): void
    {
        $mapFunction = function ($row) {
            $row['computed'] = $row['id'] * 2;
            return $row;
        };

        $result = $this->query->map($mapFunction);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that setFetchType() with different values works.
     */
    public function testSetFetchTypeWithDifferentValues(): void
    {
        $result1 = $this->query->setFetchType(true);
        $this->assertInstanceOf(Query::class, $result1);
        $this->assertSame($this->query, $result1);

        $result2 = $this->query->setFetchType(false);
        $this->assertInstanceOf(Query::class, $result2);
        $this->assertSame($this->query, $result2);
    }

    /**
     * Test that tap() with complex callback works.
     */
    public function testTapWithComplexCallback(): void
    {
        $counter = 0;
        $result = $this->query->tap(function ($q) use (&$counter) {
            $counter++;
            $q->where(['status' => 1]);
            $q->limit(10);
        });

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
        $this->assertEquals(1, $counter);
    }

    /**
     * Test complex fluent interface chaining.
     */
    public function testComplexFluentInterface(): void
    {
        $table = Table::of('users');
        $result = $this->query
            ->setTable($table)
            ->from('users', 'u')
            ->select(['id', 'name', 'email'])
            ->where(['status' => 1])
            ->whereEq('type', 'active')
            ->whereIn('category', [1, 2, 3])
            ->orderBy(['id' => SORT_ASC])
            ->groupBy(['status'])
            ->limit(10, 0)
            ->distinct(true);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that setBind() with merge false replaces parameters properly.
     */
    public function testSetBindWithMergeFalse(): void
    {
        $this->query->whereEq('status', 1);
        $this->assertArrayHasKey('status', $this->query->getBind());

        $result = $this->query->setBind(['new_param' => 'value'], false);
        $binds = $this->query->getBind();

        $this->assertInstanceOf(Query::class, $result);
        $this->assertArrayHasKey('new_param', $binds);
        $this->assertEquals('value', $binds['new_param']);
    }

    /**
     * Test that setBind() with merge true adds to parameters.
     */
    public function testSetBindWithMergeTrue(): void
    {
        $this->query->whereEq('status', 1);
        $initialBinds = $this->query->getBind();
        $this->assertArrayHasKey('status', $initialBinds);

        $result = $this->query->setBind(['new_param' => 'value'], true);
        $binds = $this->query->getBind();

        $this->assertInstanceOf(Query::class, $result);
        $this->assertArrayHasKey('status', $binds);
        $this->assertArrayHasKey('new_param', $binds);
        $this->assertEquals(1, $binds['status']);
        $this->assertEquals('value', $binds['new_param']);
    }

    public function testPredicatePipelineBacksWhere(): void
    {
        $this->query->where(['status' => 1]);

        $binds = $this->query->getBind();
        $this->assertArrayHasKey('status', $binds);
        $this->assertEquals(1, $binds['status']);
    }

    /**
     * Test that whereIn with empty array works.
     */
    public function testWhereInWithEmptyArray(): void
    {
        $result = $this->query->whereIn('status', []);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that whereEq with null value works.
     */
    public function testWhereEqWithNullValue(): void
    {
        $result = $this->query->whereEq('status', null);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that whereEq with complex value works.
     */
    public function testWhereEqWithComplexValue(): void
    {
        $result = $this->query->whereEq('metadata', ['key' => 'value', 'nested' => ['data' => 123]]);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that select with alias fields works.
     */
    public function testSelectWithAliasFields(): void
    {
        $result = $this->query->select([
            'id',
            'full_name' => 'CONCAT(first_name, \" \", last_name)',
            'count' => 'COUNT(*)'
        ]);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that groupBy with complex fields works.
     */
    public function testGroupByWithComplexFields(): void
    {
        $result = $this->query->groupBy(['YEAR(created_at)', 'status']);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that orderBy with complex fields works.
     */
    public function testOrderByWithComplexFields(): void
    {
        $result = $this->query->orderBy([
            'YEAR(created_at)' => SORT_DESC,
            'name' => SORT_ASC
        ]);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that having with complex expressions works.
     */
    public function testHavingWithComplexExpressions(): void
    {
        $result = $this->query->having([
            'COUNT(*) > 5',
            'AVG(score) >= 80'
        ]);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that indexBy with string field works.
     */
    public function testIndexByWithStringField(): void
    {
        $result = $this->query->indexBy('id');

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that with() with nested relations works.
     */
    public function testWithWithNestedRelations(): void
    {
        $result = $this->query->with([
            'profile' => ['name', 'email'],
            'posts' => ['title', 'comments']
        ]);

        $with = $this->query->getWith();
        $this->assertArrayHasKey('profile', $with);
        $this->assertArrayHasKey('posts', $with);
        $this->assertEquals(['name', 'email'], $with['profile']);
        $this->assertEquals(['title', 'comments'], $with['posts']);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }
}
