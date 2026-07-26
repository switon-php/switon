<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use Switon\Query\ConditionBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Extended test cases for ConditionBuilder class.
 *
 * Tests additional methods and edge cases not covered in the main test file.
 */
class ConditionBuilderExtendedTest extends TestCase
{
    protected ConditionBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ConditionBuilder();
    }

    /**
     * Test that whereNotBetween() creates NOT BETWEEN condition.
     */
    public function testWhereNotBetween(): void
    {
        $this->builder->whereNotBetween('age', 18, 65);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('NOT BETWEEN', $result['conditions'][0]);
        $this->assertArrayHasKey('age_min', $result['bind']);
        $this->assertArrayHasKey('age_max', $result['bind']);
        $this->assertEquals(18, $result['bind']['age_min']);
        $this->assertEquals(65, $result['bind']['age_max']);
    }

    /**
     * Test that whereNotBetween() with only min creates > condition.
     */
    public function testWhereNotBetweenWithOnlyMin(): void
    {
        $this->builder->whereNotBetween('age', 18, null);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('<', $result['conditions'][0]);
        $this->assertArrayHasKey('age', $result['bind']);
        $this->assertEquals(18, $result['bind']['age']);
    }

    /**
     * Test that whereNotBetween() with only max creates < condition.
     */
    public function testWhereNotBetweenWithOnlyMax(): void
    {
        $this->builder->whereNotBetween('age', null, 65);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('>', $result['conditions'][0]);
        $this->assertArrayHasKey('age', $result['bind']);
        $this->assertEquals(65, $result['bind']['age']);
    }

    /**
     * Test that whereNotBetween() with both null does nothing.
     */
    public function testWhereNotBetweenWithBothNull(): void
    {
        $this->builder->whereNotBetween('age', null, null);
        $result = $this->builder->build();

        $this->assertCount(0, $result['conditions']);
        $this->assertEmpty($result['bind']);
    }

    /**
     * Test that whereNotLike() creates NOT LIKE condition.
     */
    public function testWhereNotLike(): void
    {
        $this->builder->whereNotLike('name', '%John%');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('NOT LIKE', $result['conditions'][0]);
        $this->assertArrayHasKey('name', $result['bind']);
        $this->assertEquals('%John%', $result['bind']['name']);
    }

    /**
     * Test that whereNotLike() with empty value does nothing.
     */
    public function testWhereNotLikeWithEmptyValue(): void
    {
        $this->builder->whereNotLike('name', '');
        $result = $this->builder->build();

        $this->assertCount(0, $result['conditions']);
        $this->assertEmpty($result['bind']);
    }

    /**
     * Test that whereNotLike() with multiple fields creates AND condition.
     */
    public function testWhereNotLikeWithMultipleFields(): void
    {
        $this->builder->whereNotLike(['name', 'email'], '%John%');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('AND', $result['conditions'][0]);
        $this->assertStringContainsString('name', $result['conditions'][0]);
        $this->assertStringContainsString('email', $result['conditions'][0]);
    }

    /**
     * Test that whereNotContains() creates NOT LIKE with % wildcards.
     */
    public function testWhereNotContains(): void
    {
        $this->builder->whereNotContains('email', 'john');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('NOT LIKE', $result['conditions'][0]);
        $this->assertEquals('%john%', $result['bind']['email']);
    }

    /**
     * Test that whereNotStartsWith() creates NOT LIKE condition.
     */
    public function testWhereNotStartsWith(): void
    {
        $this->builder->whereNotStartsWith('email', 'admin');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('NOT LIKE', $result['conditions'][0]);
        $this->assertEquals('admin%', $result['bind']['email']);
    }

    /**
     * Test that whereNotEndsWith() creates NOT LIKE condition.
     */
    public function testWhereNotEndsWith(): void
    {
        $this->builder->whereNotEndsWith('email', '@example.com');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('NOT LIKE', $result['conditions'][0]);
        $this->assertEquals('%@example.com', $result['bind']['email']);
    }

    /**
     * Test that whereRegex() creates REGEXP condition.
     */
    public function testWhereRegex(): void
    {
        $this->builder->whereRegex('email', '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('REGEXP', $result['conditions'][0]);
        $this->assertArrayHasKey('email', $result['bind']);
    }

    /**
     * Test that whereRegex() with case insensitive flag works.
     */
    public function testWhereRegexWithCaseInsensitive(): void
    {
        $this->builder->whereRegex('name', 'john', 'i');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('REGEXP', $result['conditions'][0]);
        $this->assertArrayHasKey('name', $result['bind']);
    }

    /**
     * Test that whereNotRegex() creates NOT REGEXP condition.
     */
    public function testWhereNotRegex(): void
    {
        $this->builder->whereNotRegex('email', 'invalid');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('NOT REGEXP', $result['conditions'][0]);
        $this->assertArrayHasKey('email', $result['bind']);
    }

    /**
     * Test that whereNotRegex() with case insensitive flag works.
     */
    public function testWhereNotRegexWithCaseInsensitive(): void
    {
        $this->builder->whereNotRegex('name', 'invalid', 'i');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('NOT REGEXP', $result['conditions'][0]);
        $this->assertArrayHasKey('name', $result['bind']);
    }

    /**
     * Test that whereCmp() with equals operator redirects to whereEq().
     */
    public function testWhereCmpWithEqualsOperator(): void
    {
        $this->builder->whereCmp('status', '=', 1);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('status', $result['conditions'][0]);
        $this->assertArrayHasKey('status', $result['bind']);
        $this->assertEquals(1, $result['bind']['status']);
    }

    /**
     * Test that whereBetween() with empty string min does nothing.
     */
    public function testWhereBetweenWithEmptyStringMin(): void
    {
        $this->builder->whereBetween('age', '', 65);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('<=', $result['conditions'][0]);
        $this->assertArrayHasKey('age', $result['bind']);
        $this->assertEquals(65, $result['bind']['age']);
    }

    /**
     * Test that whereBetween() with empty string max does nothing.
     */
    public function testWhereBetweenWithEmptyStringMax(): void
    {
        $this->builder->whereBetween('age', 18, '');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('>=', $result['conditions'][0]);
        $this->assertArrayHasKey('age', $result['bind']);
        $this->assertEquals(18, $result['bind']['age']);
    }

    /**
     * Test that whereNotBetween() with empty string min does nothing.
     */
    public function testWhereNotBetweenWithEmptyStringMin(): void
    {
        $this->builder->whereNotBetween('age', '', 65);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('>', $result['conditions'][0]);
        $this->assertArrayHasKey('age', $result['bind']);
        $this->assertEquals(65, $result['bind']['age']);
    }

    /**
     * Test that whereNotBetween() with empty string max does nothing.
     */
    public function testWhereNotBetweenWithEmptyStringMax(): void
    {
        $this->builder->whereNotBetween('age', 18, '');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('<', $result['conditions'][0]);
        $this->assertArrayHasKey('age', $result['bind']);
        $this->assertEquals(18, $result['bind']['age']);
    }

    /**
     * Test that whereStartsWith() with length parameter.
     */
    public function testWhereStartsWithWithLength(): void
    {
        $this->builder->whereStartsWith('code', 'A', 5);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('LIKE', $result['conditions'][0]);
        $this->assertEquals('A____', $result['bind']['code']);
    }

    /**
     * Test that whereNotStartsWith() with length parameter.
     */
    public function testWhereNotStartsWithWithLength(): void
    {
        $this->builder->whereNotStartsWith('code', 'A', 5);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('NOT LIKE', $result['conditions'][0]);
        $this->assertEquals('A____', $result['bind']['code']);
    }

    /**
     * Test that whereRaw() creates raw condition.
     */
    public function testWhereRaw(): void
    {
        $this->builder->whereRaw('custom_field IS NOT NULL', ['param' => 'value']);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertEquals('custom_field IS NOT NULL', $result['conditions'][0]);
        $this->assertArrayHasKey('param', $result['bind']);
        $this->assertEquals('value', $result['bind']['param']);
    }

    /**
     * Test that whereNotInset() creates FIND_IN_SET condition.
     */
    public function testWhereNotInset(): void
    {
        $this->builder->whereNotInset('tags', 'php');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('FIND_IN_SET', $result['conditions'][0]);
        $this->assertStringContainsString('=0', $result['conditions'][0]);
        $this->assertArrayHasKey('tags', $result['bind']);
        $this->assertEquals('php', $result['bind']['tags']);
    }

    /**
     * Test that buildIn() with integer values creates direct IN clause.
     */
    public function testBuildInWithIntegerValues(): void
    {
        $this->builder->whereIn('id', [1, 2, 3]);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('IN (1, 2, 3)', $result['conditions'][0]);
        $this->assertEmpty($result['bind']); // No bind params for integers
    }

    /**
     * Test that buildNotIn() with integer values creates direct NOT IN clause.
     */
    public function testBuildNotInWithIntegerValues(): void
    {
        $this->builder->whereNotIn('id', [1, 2, 3]);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('NOT IN (1, 2, 3)', $result['conditions'][0]);
        $this->assertEmpty($result['bind']); // No bind params for integers
    }

    /**
     * Test that buildLike() with multiple fields creates unique bind keys.
     */
    public function testBuildLikeWithMultipleFieldsUniqueKeys(): void
    {
        $this->builder->whereLike(['name', 'name'], '%test%'); // Same field name twice
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('OR', $result['conditions'][0]);
    }

    /**
     * Test that buildNotLike() with multiple fields creates unique bind keys.
     */
    public function testBuildNotLikeWithMultipleFieldsUniqueKeys(): void
    {
        $this->builder->whereNotLike(['name', 'name'], '%test%'); // Same field name twice
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('AND', $result['conditions'][0]);
    }

    /**
     * Test that buildEq() with null value creates IS NULL condition.
     */
    public function testBuildEqWithNullValue(): void
    {
        $this->builder->whereEq('deleted_at', null);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('IS NULL', $result['conditions'][0]);
        $this->assertEmpty($result['bind']);
    }

    /**
     * Test that buildEq() with field containing dots creates proper bind key.
     */
    public function testBuildEqWithDottedField(): void
    {
        $this->builder->whereEq('user.profile.status', 'active');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('[user].[profile].[status]', $result['conditions'][0]);
        $this->assertArrayHasKey('user_profile_status', $result['bind']);
        $this->assertEquals('active', $result['bind']['user_profile_status']);
    }

    /**
     * Test that buildCmp() with ~= operator handles special cases.
     */
    public function testBuildCmpWithTildeEqualsOperator(): void
    {
        $this->builder->whereCmp('value', '~=', 0);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('IS NULL OR', $result['conditions'][0]);
        $this->assertStringContainsString('=0', $result['conditions'][0]);
    }

    /**
     * Test that buildCmp() with ~= operator handles empty string.
     */
    public function testBuildCmpWithTildeEqualsOperatorEmptyString(): void
    {
        $this->builder->whereCmp('value', '~=', '');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('IS NULL OR', $result['conditions'][0]);
        $this->assertStringContainsString('=\'\'', $result['conditions'][0]);
    }

    /**
     * Test that buildCmp() with != operator handles null.
     */
    public function testBuildCmpWithNotEqualsOperatorNull(): void
    {
        $this->builder->whereCmp('field', '!=', null);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('IS NOT NULL', $result['conditions'][0]);
        $this->assertEmpty($result['bind']);
    }

    /**
     * Test that buildCmp() with comparison operators works correctly.
     */
    public function testBuildCmpWithComparisonOperators(): void
    {
        $operators = ['>', '>=', '<', '<='];

        foreach ($operators as $op) {
            $builder = new ConditionBuilder();
            $builder->whereCmp('field', $op, 100);
            $result = $builder->build();

            $this->assertCount(1, $result['conditions']);
            $this->assertStringContainsString($op, $result['conditions'][0]);
            $this->assertArrayHasKey('field', $result['bind']);
            $this->assertEquals(100, $result['bind']['field']);
        }
    }

    /**
     * Test that buildMod() creates correct MOD condition.
     */
    public function testBuildMod(): void
    {
        $this->builder->whereMod('id', 2, 0);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('%2=0', $result['conditions'][0]);
        $this->assertEmpty($result['bind']);
    }

    /**
     * Test that buildBetween() creates correct BETWEEN condition.
     */
    public function testBuildBetween(): void
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
     * Test that buildNotBetween() creates correct NOT BETWEEN condition.
     */
    public function testBuildNotBetween(): void
    {
        $this->builder->whereNotBetween('age', 18, 65);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('NOT BETWEEN', $result['conditions'][0]);
        $this->assertArrayHasKey('age_min', $result['bind']);
        $this->assertArrayHasKey('age_max', $result['bind']);
        $this->assertEquals(18, $result['bind']['age_min']);
        $this->assertEquals(65, $result['bind']['age_max']);
    }

    /**
     * Test that buildInset() creates correct FIND_IN_SET condition.
     */
    public function testBuildInset(): void
    {
        $this->builder->whereInset('tags', 'php');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('FIND_IN_SET', $result['conditions'][0]);
        $this->assertArrayHasKey('tags', $result['bind']);
        $this->assertEquals('php', $result['bind']['tags']);
    }

    /**
     * Test that buildNotInset() creates correct FIND_IN_SET condition.
     */
    public function testBuildNotInset(): void
    {
        $this->builder->whereNotInset('tags', 'php');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('FIND_IN_SET', $result['conditions'][0]);
        $this->assertStringContainsString('=0', $result['conditions'][0]);
        $this->assertArrayHasKey('tags', $result['bind']);
        $this->assertEquals('php', $result['bind']['tags']);
    }

    /**
     * Test that buildNull() creates correct IS NULL condition.
     */
    public function testBuildNull(): void
    {
        $this->builder->whereNull('deleted_at');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('IS NULL', $result['conditions'][0]);
        $this->assertEmpty($result['bind']);
    }

    /**
     * Test that buildNotNull() creates correct IS NOT NULL condition.
     */
    public function testBuildNotNull(): void
    {
        $this->builder->whereNotNull('created_at');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('IS NOT NULL', $result['conditions'][0]);
        $this->assertEmpty($result['bind']);
    }

    /**
     * Test that buildRegex() creates correct REGEXP condition.
     */
    public function testBuildRegex(): void
    {
        $this->builder->whereRegex('email', '^[a-z]+', 'i');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('REGEXP', $result['conditions'][0]);
        $this->assertArrayHasKey('email', $result['bind']);
        $this->assertEquals('^[a-z]+', $result['bind']['email']);
    }

    /**
     * Test that buildNotRegex() creates correct NOT REGEXP condition.
     */
    public function testBuildNotRegex(): void
    {
        $this->builder->whereNotRegex('email', '^[a-z]+', 'i');
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('NOT REGEXP', $result['conditions'][0]);
        $this->assertArrayHasKey('email', $result['bind']);
        $this->assertEquals('^[a-z]+', $result['bind']['email']);
    }

    /**
     * Test that buildExpr() creates raw expression condition.
     */
    public function testBuildExpr(): void
    {
        $this->builder->whereExpr('field1 > field2', ['param1' => 'value1']);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertEquals('field1 > field2', $result['conditions'][0]);
        $this->assertArrayHasKey('param1', $result['bind']);
        $this->assertEquals('value1', $result['bind']['param1']);
    }

    /**
     * Test that buildRaw() creates raw condition.
     */
    public function testBuildRaw(): void
    {
        $this->builder->whereRaw('custom_condition', ['param' => 'value']);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertEquals('custom_condition', $result['conditions'][0]);
        $this->assertArrayHasKey('param', $result['bind']);
        $this->assertEquals('value', $result['bind']['param']);
    }
}
