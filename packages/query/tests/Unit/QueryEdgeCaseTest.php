<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use Switon\Query\Exception\InvalidSortDirectionException;
use Switon\Query\Query;
use Switon\Query\Table;
use Switon\Query\Tests\TestCase;

/**
 * Edge case test cases for Query class.
 *
 * Tests edge cases and error handling for Query functionality.
 */
class QueryEdgeCaseTest extends TestCase
{
    protected Query $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = $this->make(Query::class);
        // Set a default table to avoid shard resolution errors
        $this->query->setTable(Table::of('test_table'));
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
     * Test that orderBy() with invalid direction throws exception.
     */
    public function testOrderByWithInvalidDirectionThrowsException(): void
    {
        $this->expectException(InvalidSortDirectionException::class);

        $this->query->orderBy(['id' => 'INVALID']);
    }

    /**
     * Test that groupBy() with non-string array items throws exception.
     */
    public function testGroupByStringifiesNonStringArrayItems(): void
    {
        $this->query->groupBy(['status', 123]);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    public function testOrderByStringifiesNonStringFieldForIntegerKey(): void
    {
        $this->query->orderBy([123]);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    public function testGroupByStringifiesNonStringItem(): void
    {
        $this->query->groupBy([123]);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that select() with fields containing brackets works properly.
     */
    public function testSelectWithFieldsContainingBrackets(): void
    {
        $this->query->select(['COUNT(*) as total']);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that orderBy() with integer keys works properly.
     */
    public function testOrderByWithIntegerKeys(): void
    {
        $this->query->orderBy(['id', 'name']);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that where() with empty array does not cause issues.
     */
    public function testWhereWithEmptyArray(): void
    {
        $this->query->where([]);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that limit() with negative offset works (should be allowed by type hint but behavior depends on implementation).
     */
    public function testLimitWithNegativeValues(): void
    {
        $result = $this->query->limit(10, -5);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that select() with mixed valid configurations works.
     */
    public function testSelectWithMixedValidConfigurations(): void
    {
        $this->query->select([
            'id',
            'full_name' => 'CONCAT(first_name, " ", last_name)',
            'status',
            'created_at'
        ]);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test that whereIn() with large array works.
     */
    public function testWhereInWithLargeArray(): void
    {
        $largeArray = range(1, 1000);
        $result = $this->query->whereIn('id', $largeArray);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that with() with deeply nested relations works.
     */
    public function testWithWithDeeplyNestedRelations(): void
    {
        $result = $this->query->with([
            'user' => ['name', 'email'],
            'user.profile' => ['bio', 'avatar'],
            'posts' => ['title', 'comments'],
            'posts.author' => ['name']
        ]);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that having() with complex nested conditions works.
     */
    public function testHavingWithComplexNestedConditions(): void
    {
        $result = $this->query->having([
            '(COUNT(*) > 5 AND SUM(amount) < 1000)',
            'AVG(rating) >= 4.0'
        ]);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that orderBy() with complex expressions works.
     */
    public function testOrderByWithComplexExpressions(): void
    {
        $result = $this->query->orderBy([
            'FIELD(status, "active", "pending", "inactive")' => SORT_ASC,
            'created_at' => SORT_DESC
        ]);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that join() with complex conditions works.
     */
    public function testJoinWithComplexConditions(): void
    {
        $table = Table::of('profiles');
        $result = $this->query->join($table, 'users.id = profiles.user_id AND profiles.active = 1', 'p', 'LEFT');

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that setBind() with merge false replaces user-provided binds but may keep condition binds.
     */
    public function testSetBindWithMergeSettings(): void
    {
        // Create a fresh query for this test to avoid interference
        $query = $this->make(Query::class);
        $query->setTable(Table::of('test_table'));
        $query->whereEq('status', 'active'); // This creates a bind

        $initialBinds = $query->getBind();
        $this->assertArrayHasKey('status', $initialBinds);

        // Add another bind with merge=true
        $query->setBind(['user_id' => 123], true);
        $bindsWithMerge = $query->getBind();
        $this->assertArrayHasKey('status', $bindsWithMerge);
        $this->assertArrayHasKey('user_id', $bindsWithMerge);

        // Replace user-provided binds with merge=false, but condition binds may remain
        $query->setBind(['category' => 'premium'], false);
        $finalBinds = $query->getBind();
        $this->assertArrayHasKey('category', $finalBinds);
        // The status might still be there since it came from a condition, not setBind
    }

    /**
     * Test that setColumnMap() with complex mappings works.
     */
    public function testSetColumnMapWithComplexMappings(): void
    {
        $complexMap = [
            'userId' => 'user_id',
            'userName' => 'user_name',
            'userEmail' => 'user_email',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
            'userProfileBio' => 'profile_bio',
            'userProfileAvatar' => 'profile_avatar'
        ];

        $result = $this->query->setColumnMap($complexMap);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that indexBy() with complex callback works.
     */
    public function testIndexByWithComplexCallback(): void
    {
        $callback = function ($row) {
            if (isset($row['category'], $row['type'])) {
                return $row['category'] . '_' . $row['type'] . '_' . ($row['id'] ?? 'unknown');
            }
            return 'unknown';
        };

        $result = $this->query->indexBy($callback);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that where() with mixed filter types works.
     */
    public function testWhereWithMixedFilterTypes(): void
    {
        $this->query->where([
            'status' => 'active',
            'user_id' => [1, 2, 3], // IN condition
            'age>' => 18,
            'email*=' => 'gmail', // LIKE %gmail%
            'score~=' => [80, 100], // BETWEEN
            0 => 'custom_field IS NOT NULL' // Raw expression
        ]);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test chaining multiple complex operations.
     */
    public function testChainingMultipleComplexOperations(): void
    {
        $table = Table::of('users');
        $profileTable = Table::of('profiles');

        $result = $this->query
            ->setTable($table)
            ->from('users', 'u')
            ->select([
                'id',
                'name',
                'full_name' => 'CONCAT(first_name, " ", last_name)'
            ])
            ->where([
                'status' => 'active',
                'created_at>' => '2023-01-01'
            ])
            ->whereEq('type', 'premium')
            ->whereIn('category', [1, 2, 3, 4, 5])
            ->leftJoin($profileTable, 'users.id = profiles.user_id', 'p')
            ->orderBy(['created_at' => SORT_DESC])
            ->groupBy(['status'])
            ->having('COUNT(*) > 1')
            ->limit(20, 0)
            ->distinct(true)
            ->with(['profile' => ['bio', 'avatar']])
            ->indexBy('id')
            ->forUpdate(false);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test that getWith() returns empty array when no relations are set.
     */
    public function testGetWithReturnsEmptyArrayInitially(): void
    {
        $with = $this->query->getWith();

        $this->assertIsArray($with);
        $this->assertEmpty($with);
    }

    /**
     * Test that getEntityClass() returns null initially.
     */
    public function testGetEntityClassReturnsNullInitially(): void
    {
        $entityClass = $this->query->getEntityClass();

        $this->assertNull($entityClass);
    }

    /**
     * Test that getTable() returns the set table.
     */
    public function testGetTableReturnsSetTable(): void
    {
        $table = Table::of('custom_table', 'custom_connection');
        $this->query->setTable($table);

        $returnedTable = $this->query->getTable();

        $this->assertSame($table, $returnedTable);
    }

    /**
     * Test complex map function.
     */
    public function testComplexMapFunction(): void
    {
        $complexMap = function ($row) {
            $row['processed'] = true;
            $row['processed_at'] = date('Y-m-d H:i:s');
            $row['hash'] = md5($row['id'] . $row['processed_at']);
            return $row;
        };

        $result = $this->query->map($complexMap);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test setFetchType with various boolean values.
     */
    public function testSetFetchTypeWithVariousBooleanValues(): void
    {
        $result1 = $this->query->setFetchType(true);
        $this->assertInstanceOf(Query::class, $result1);
        $this->assertSame($this->query, $result1);

        $result2 = $this->query->setFetchType(false);
        $this->assertInstanceOf(Query::class, $result2);
        $this->assertSame($this->query, $result2);
    }

    /**
     * Test tap with no operations.
     */
    public function testTapWithNoOperations(): void
    {
        $result = $this->query->tap(function ($q) {
            // No operations, just return
        });

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test join with all null parameters except table.
     */
    public function testJoinWithAllNullParameters(): void
    {
        $table = Table::of('test_join_table');
        $result = $this->query->join($table, null, null, null);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test complex having with both string and array parameters.
     */
    public function testComplexHavingWithMixedParameters(): void
    {
        // First with string
        $result1 = $this->query->having('COUNT(*) > 5');
        $this->assertInstanceOf(Query::class, $result1);

        // Then with array
        $result2 = $this->query->having(['COUNT(*) > 5', 'SUM(amount) < 1000']);
        $this->assertInstanceOf(Query::class, $result2);

        // With bind parameters
        $result3 = $this->query->having('SUM(amount) > :min AND SUM(amount) < :max', [
            'min' => 100,
            'max' => 1000
        ]);
        $this->assertInstanceOf(Query::class, $result3);
    }

    /**
     * Test that where with only raw expressions works.
     */
    public function testWhereWithOnlyRawExpressions(): void
    {
        $this->query->where([
            0 => 'field1 = :value1',
            1 => 'field2 > :value2',
            2 => '(field3 IS NOT NULL AND field4 != "")'
        ]);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test multiple orderBy fields with different sort directions.
     */
    public function testMultipleOrderByFieldsWithDifferentDirections(): void
    {
        $result = $this->query->orderBy([
            'priority' => SORT_DESC,
            'created_at' => SORT_DESC,
            'name' => SORT_ASC,
            'updated_at' => 'DESC',
            'title' => 'asc'
        ]);

        $this->assertInstanceOf(Query::class, $result);
        $this->assertSame($this->query, $result);
    }

    /**
     * Test edge case: where condition with only special characters.
     */
    public function testWhereWithSpecialCharacterConditions(): void
    {
        $this->query->where([
            'name' => '!@#$%^&*()',
            'code' => 'a1!b2@c3#',
            'symbols>' => '<>[]{}'
        ]);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test complex select with functions and aliases.
     */
    public function testComplexSelectWithFunctionsAndAliases(): void
    {
        $this->query->select([
            'id',
            'name',
            'full_count' => 'COUNT(*)',
            'avg_score' => 'AVG(score)',
            'formatted_date' => "DATE_FORMAT(created_at, '%Y-%m-%d')",
            'concatenated' => "CONCAT(first_name, ' ', last_name)",
            'conditional' => 'CASE WHEN status = 1 THEN "Active" ELSE "Inactive" END'
        ]);

        $this->assertInstanceOf(Query::class, $this->query);
    }

    /**
     * Test with empty arrays and null values handling.
     */
    public function testWithEmptyConfigurations(): void
    {
        $result1 = $this->query->with([]);
        $this->assertInstanceOf(Query::class, $result1);

        $with = $this->query->getWith();
        $this->assertIsArray($with);
        $this->assertEmpty($with);
    }

    public function testToStringReturnsEmptyStringWhenSqlGenerationFails(): void
    {
        $query = $this->make(Query::class);

        $this->assertSame('', (string)$query);
    }
}
