<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use Switon\Query\ConditionBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Test cases for ConditionBuilder class.
 */
class ConditionBuilderTest extends TestCase
{
    protected ConditionBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ConditionBuilder();
    }

    /**
     * Test that whereEq() creates equality condition.
     */
    public function testWhereEq(): void
    {
        $this->builder->whereEq('status', 1);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('status', $result['conditions'][0]);
        $this->assertArrayHasKey('status', $result['bind']);
        $this->assertEquals(1, $result['bind']['status']);
    }

    public function testGetPredicatesReturnsCollectedPredicates(): void
    {
        $this->builder->whereEq('status', 1);
        $predicates = $this->builder->getPredicates();

        $this->assertCount(1, $predicates);
        $this->assertSame('eq', $predicates[0]['type']);
        $this->assertSame('status', $predicates[0]['field']);
        $this->assertSame(1, $predicates[0]['value']);
    }

    /**
     * Test that whereEq() with null creates IS NULL condition.
     */
    public function testWhereEqWithNull(): void
    {
        $this->builder->whereEq('deleted_at', null);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('IS NULL', $result['conditions'][0]);
        $this->assertEmpty($result['bind']);
    }

    /**
     * Test that whereCmp() creates comparison condition.
     */
    public function testWhereCmp(): void
    {
        $this->builder->whereCmp('age', '>', 18);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('age', $result['conditions'][0]);
        $this->assertStringContainsString('>', $result['conditions'][0]);
        $this->assertArrayHasKey('age', $result['bind']);
        $this->assertEquals(18, $result['bind']['age']);
    }

    /**
     * Test that whereIn() creates IN condition.
     */
    public function testWhereIn(): void
    {
        $this->builder->whereIn('status', [1, 2, 3]);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('IN', $result['conditions'][0]);
        $this->assertStringContainsString('1, 2, 3', $result['conditions'][0]);
    }

    /**
     * Test that whereIn() with empty array creates FALSE condition.
     */
    public function testWhereInWithEmptyArray(): void
    {
        $this->builder->whereIn('status', []);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertEquals('FALSE', $result['conditions'][0]);
        $this->assertEmpty($result['bind']);
    }

    /**
     * Test that whereNotIn() creates NOT IN condition.
     */
    public function testWhereNotIn(): void
    {
        $this->builder->whereNotIn('role_id', [1, 2]);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('NOT IN', $result['conditions'][0]);
    }

    /**
     * Test that whereNotIn() with empty array is ignored.
     */
    public function testWhereNotInWithEmptyArray(): void
    {
        $this->builder->whereNotIn('status', []);
        $result = $this->builder->build();

        // Empty array should result in null condition (ignored)
        $this->assertCount(0, $result['conditions']);
        $this->assertEmpty($result['bind']);
    }

    /**
     * Test that whereBetween() creates BETWEEN condition.
     */
    public function testWhereBetween(): void
    {
        $this->builder->whereBetween('age', 18, 65);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('BETWEEN', $result['conditions'][0]);
        $this->assertArrayHasKey('age_min', $result['bind']);
        $this->assertArrayHasKey('age_max', $result['bind']);
        $this->assertEquals(18, $result['bind']['age_min']);
        $this->assertEquals(65, $result['bind']['age_max']);
    }

    /**
     * Test that whereBetween() with only min creates >= condition.
     */
    public function testWhereBetweenWithOnlyMin(): void
    {
        $this->builder->whereBetween('age', 18, null);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('>=', $result['conditions'][0]);
        $this->assertArrayHasKey('age', $result['bind']);
        $this->assertEquals(18, $result['bind']['age']);
    }

    /**
     * Test that whereBetween() with only max creates <= condition.
     */
    public function testWhereBetweenWithOnlyMax(): void
    {
        $this->builder->whereBetween('age', null, 65);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('<=', $result['conditions'][0]);
        $this->assertArrayHasKey('age', $result['bind']);
        $this->assertEquals(65, $result['bind']['age']);
    }

    /**
     * Test that whereBetween() with both null does nothing.
     */
    public function testWhereBetweenWithBothNull(): void
    {
        $this->builder->whereBetween('age', null, null);
        $result = $this->builder->build();

        $this->assertCount(0, $result['conditions']);
        $this->assertEmpty($result['bind']);
    }

    /**
     * Test that whereLike() creates LIKE condition.
     */
    public function testWhereLike(): void
    {
        $this->builder->whereLike('name', '%John%');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('LIKE', $result['conditions'][0]);
        $this->assertArrayHasKey('name', $result['bind']);
        $this->assertEquals('%John%', $result['bind']['name']);
    }

    /**
     * Test that whereLike() with empty value does nothing.
     */
    public function testWhereLikeWithEmptyValue(): void
    {
        $this->builder->whereLike('name', '');
        $result = $this->builder->build();

        $this->assertCount(0, $result['conditions']);
        $this->assertEmpty($result['bind']);
    }

    /**
     * Test that whereContains() wraps value with % wildcards.
     */
    public function testWhereContains(): void
    {
        $this->builder->whereContains('email', 'john');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('LIKE', $result['conditions'][0]);
        $this->assertEquals('%john%', $result['bind']['email']);
    }

    /**
     * Test that whereStartsWith() appends % wildcard.
     */
    public function testWhereStartsWith(): void
    {
        $this->builder->whereStartsWith('email', 'admin');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('LIKE', $result['conditions'][0]);
        $this->assertEquals('admin%', $result['bind']['email']);
    }

    /**
     * Test that whereEndsWith() prepends % wildcard.
     */
    public function testWhereEndsWith(): void
    {
        $this->builder->whereEndsWith('email', '@example.com');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('LIKE', $result['conditions'][0]);
        $this->assertEquals('%@example.com', $result['bind']['email']);
    }

    /**
     * Test that whereNull() creates IS NULL condition.
     */
    public function testWhereNull(): void
    {
        $this->builder->whereNull('deleted_at');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('IS NULL', $result['conditions'][0]);
        $this->assertEmpty($result['bind']);
    }

    /**
     * Test that whereNotNull() creates IS NOT NULL condition.
     */
    public function testWhereNotNull(): void
    {
        $this->builder->whereNotNull('email');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('IS NOT NULL', $result['conditions'][0]);
        $this->assertEmpty($result['bind']);
    }

    /**
     * Test that whereMod() creates MOD condition.
     */
    public function testWhereMod(): void
    {
        $this->builder->whereMod('id', 2, 0);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('%', $result['conditions'][0]);
        $this->assertStringContainsString('2', $result['conditions'][0]);
        $this->assertStringContainsString('0', $result['conditions'][0]);
        $this->assertEmpty($result['bind']);
    }

    /**
     * Test that whereInset() creates FIND_IN_SET condition.
     */
    public function testWhereInset(): void
    {
        $this->builder->whereInset('tags', 'php');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('FIND_IN_SET', $result['conditions'][0]);
        $this->assertArrayHasKey('tags', $result['bind']);
        $this->assertEquals('php', $result['bind']['tags']);
    }

    /**
     * Test that whereExpr() creates raw SQL expression.
     */
    public function testWhereExpr(): void
    {
        $this->builder->whereExpr('age > :min AND age < :max', ['min' => 18, 'max' => 65]);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertEquals('age > :min AND age < :max', $result['conditions'][0]);
        $this->assertArrayHasKey('min', $result['bind']);
        $this->assertArrayHasKey('max', $result['bind']);
        $this->assertEquals(18, $result['bind']['min']);
        $this->assertEquals(65, $result['bind']['max']);
    }

    /**
     * Test that multiple conditions are combined with AND logic.
     */
    public function testMultipleConditions(): void
    {
        $this->builder->whereEq('status', 1);
        $this->builder->whereCmp('age', '>', 18);
        $this->builder->whereIn('role_id', [1, 2, 3]);
        $result = $this->builder->build();

        $this->assertCount(3, $result['conditions']);
        $this->assertArrayHasKey('status', $result['bind']);
        $this->assertArrayHasKey('age', $result['bind']);
    }

    /**
     * Test that whereLike() with multiple fields creates OR condition.
     */
    public function testWhereLikeWithMultipleFields(): void
    {
        $this->builder->whereLike(['name', 'email'], '%John%');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('OR', $result['conditions'][0]);
        $this->assertStringContainsString('name', $result['conditions'][0]);
        $this->assertStringContainsString('email', $result['conditions'][0]);
    }

    /**
     * Test that whereLike() accepts comma-separated string for fields.
     */
    public function testWhereLikeWithCommaSeparatedFields(): void
    {
        $this->builder->whereLike('name,email', '%John%');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('OR', $result['conditions'][0]);
    }

    /**
     * Test that getConditions() returns built conditions.
     */
    public function testGetConditions(): void
    {
        $this->builder->whereEq('status', 1);
        $this->builder->whereCmp('age', '>', 18);

        $conditions = $this->builder->getConditions();

        $this->assertCount(2, $conditions);
    }

    /**
     * Test that getBind() returns all bind parameters.
     */
    public function testGetBind(): void
    {
        $this->builder->whereEq('status', 1);
        $this->builder->whereCmp('age', '>', 18);

        $bind = $this->builder->getBind();

        $this->assertArrayHasKey('status', $bind);
        $this->assertArrayHasKey('age', $bind);
        $this->assertEquals(1, $bind['status']);
        $this->assertEquals(18, $bind['age']);
    }

    /**
     * Test that getBind() with key returns specific parameter.
     */
    public function testGetBindWithKey(): void
    {
        $this->builder->whereEq('status', 1);

        $value = $this->builder->getBind('status');

        $this->assertEquals(1, $value);
    }

    /**
     * Test that setBind() merges bind parameters.
     */
    public function testSetBind(): void
    {
        $this->builder->whereEq('status', 1);
        $this->builder->setBind(['custom' => 'value'], true);

        $bind = $this->builder->getBind();

        $this->assertArrayHasKey('status', $bind);
        $this->assertArrayHasKey('custom', $bind);
        $this->assertEquals(1, $bind['status']);
        $this->assertEquals('value', $bind['custom']);
    }

    /**
     * Test that setBind() replaces bind parameters when merge is false.
     */
    public function testSetBindWithoutMerge(): void
    {
        $this->builder->whereEq('status', 1);
        $this->builder->setBind(['custom' => 'value'], false);

        $bind = $this->builder->getBind();

        $this->assertArrayNotHasKey('status', $bind);
        $this->assertArrayHasKey('custom', $bind);
    }

    /**
     * Test that getPredicates() returns collected predicates.
     */
    public function testGetPredicates(): void
    {
        $this->builder->whereEq('status', 1);
        $this->builder->whereCmp('age', '>', 18);

        $predicates = $this->builder->getPredicates();

        $this->assertCount(2, $predicates);
        $this->assertEquals('eq', $predicates[0]['type']);
        $this->assertEquals('cmp', $predicates[1]['type']);
    }

    /**
     * Test that build() caches results.
     */
    public function testBuildCaching(): void
    {
        $this->builder->whereEq('status', 1);

        $result1 = $this->builder->build();
        $result2 = $this->builder->build();

        $this->assertSame($result1, $result2);
    }

    /**
     * Test that adding condition clears cache.
     */
    public function testBuildCacheInvalidation(): void
    {
        $this->builder->whereEq('status', 1);
        $result1 = $this->builder->build();

        $this->builder->whereCmp('age', '>', 18);
        $result2 = $this->builder->build();

        $this->assertNotSame($result1, $result2);
        $this->assertCount(1, $result1['conditions']);
        $this->assertCount(2, $result2['conditions']);
    }

    /**
     * Test that where() parses filter array correctly.
     */
    public function testWhereWithFilterArray(): void
    {
        $this->builder->where(['status' => 1, 'age>' => 18, 'name*=' => 'John']);
        $result = $this->builder->build();

        $this->assertGreaterThan(0, count($result['conditions']));
    }

    /**
     * Test field name normalization with dots.
     */
    public function testFieldNormalizationWithDots(): void
    {
        $this->builder->whereEq('user.status', 1);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('[user].[status]', $result['conditions'][0]);
        $this->assertArrayHasKey('user_status', $result['bind']);
    }
}
