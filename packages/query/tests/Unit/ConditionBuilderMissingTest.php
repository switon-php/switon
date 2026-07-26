<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use Switon\Db\Exception\UnknownOperatorException;
use Switon\Query\ConditionBuilder;
use Switon\Query\Exception\InvalidFilterException;
use PHPUnit\Framework\TestCase;

/**
 * Test cases for ConditionBuilder missing coverage - commonly used features.
 */
class ConditionBuilderMissingTest extends TestCase
{
    protected ConditionBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ConditionBuilder();
    }

    /**
     * Test buildCmp() with ~= operator and normal value (not 0 or empty string).
     */
    public function testBuildCmpWithTildeEqualsNormalValue(): void
    {
        $this->builder->whereCmp('status', '~=', 1);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        // ~= with normal value uses = with bind key directly (status not :status)
        $condition = $result['conditions'][0];
        $this->assertStringContainsString('[status]=', $condition);
        $this->assertStringContainsString('status', $condition); // bind key is used directly
        $this->assertArrayHasKey('status', $result['bind']);
        $this->assertEquals(1, $result['bind']['status']);
    }

    /**
     * Test buildIn() with string values uses bind parameters.
     */
    public function testBuildInWithStringValues(): void
    {
        $this->builder->whereIn('status', ['active', 'pending', 'deleted']);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('IN', $result['conditions'][0]);
        // String values should use bind parameters
        $this->assertNotEmpty($result['bind']);
        $this->assertArrayHasKey('status_in_0', $result['bind']);
        $this->assertEquals('active', $result['bind']['status_in_0']);
    }

    /**
     * Test buildNotIn() with string values uses bind parameters.
     */
    public function testBuildNotInWithStringValues(): void
    {
        $this->builder->whereNotIn('status', ['deleted', 'archived']);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('NOT IN', $result['conditions'][0]);
        // String values should use bind parameters
        $this->assertNotEmpty($result['bind']);
        $this->assertArrayHasKey('status_not_in_0', $result['bind']);
        $this->assertEquals('deleted', $result['bind']['status_not_in_0']);
    }

    /**
     * Test whereCmp() with > operator.
     */
    public function testWhereCmpWithGreaterThan(): void
    {
        $this->builder->whereCmp('age', '>', 18);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('[age]>:age', $result['conditions'][0]);
        $this->assertArrayHasKey('age', $result['bind']);
        $this->assertEquals(18, $result['bind']['age']);
    }

    /**
     * Test whereCmp() with >= operator.
     */
    public function testWhereCmpWithGreaterThanOrEqual(): void
    {
        $this->builder->whereCmp('age', '>=', 18);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('[age]>=:age', $result['conditions'][0]);
        $this->assertArrayHasKey('age', $result['bind']);
        $this->assertEquals(18, $result['bind']['age']);
    }

    /**
     * Test whereCmp() with < operator.
     */
    public function testWhereCmpWithLessThan(): void
    {
        $this->builder->whereCmp('age', '<', 65);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('[age]<:age', $result['conditions'][0]);
        $this->assertArrayHasKey('age', $result['bind']);
        $this->assertEquals(65, $result['bind']['age']);
    }

    /**
     * Test whereCmp() with <= operator.
     */
    public function testWhereCmpWithLessThanOrEqual(): void
    {
        $this->builder->whereCmp('age', '<=', 65);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('[age]<=:age', $result['conditions'][0]);
        $this->assertArrayHasKey('age', $result['bind']);
        $this->assertEquals(65, $result['bind']['age']);
    }

    /**
     * Test whereCmp() with != operator and non-null value.
     */
    public function testWhereCmpWithNotEqualsNonNull(): void
    {
        $this->builder->whereCmp('status', '!=', 0);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('[status]!=:status', $result['conditions'][0]);
        $this->assertArrayHasKey('status', $result['bind']);
        $this->assertEquals(0, $result['bind']['status']);
    }

    /**
     * Test where() with empty array throws exception for unknown type.
     */
    public function testWhereWithEmptyAssociativeArray(): void
    {
        // Empty array should trigger whereIn which creates FALSE condition
        // But if it's truly empty (no index 0), it throws exception
        // Actually, empty array with isset($value[0]) is false, so it goes to whereIn
        // which then creates FALSE condition. But if array is associative without 0 index,
        // isset($value[0]) is false and !$value is true, so it goes to whereIn with empty array
        $this->builder->where(['status' => []]);
        $result = $this->builder->build();

        // Empty array in whereIn creates FALSE condition
        $this->assertCount(1, $result['conditions']);
        $this->assertEquals('FALSE', $result['conditions'][0]);
    }

    /**
     * Test where() with comma-separated fields and *= operator.
     */
    public function testWhereWithCommaSeparatedFieldsContains(): void
    {
        $this->builder->where(['name,email*=' => 'john']);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('OR', $result['conditions'][0]);
        $this->assertStringContainsString('name', $result['conditions'][0]);
        $this->assertStringContainsString('email', $result['conditions'][0]);
    }

    /**
     * Test where() with comma-separated fields and ^= operator.
     */
    public function testWhereWithCommaSeparatedFieldsStartsWith(): void
    {
        $this->builder->where(['name,email^=' => 'admin']);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('OR', $result['conditions'][0]);
        $this->assertStringContainsString('name', $result['conditions'][0]);
        $this->assertStringContainsString('email', $result['conditions'][0]);
    }

    /**
     * Test where() with comma-separated fields and $= operator.
     */
    public function testWhereWithCommaSeparatedFieldsEndsWith(): void
    {
        $this->builder->where(['name,email$=' => '.com']);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('OR', $result['conditions'][0]);
        $this->assertStringContainsString('name', $result['conditions'][0]);
        $this->assertStringContainsString('email', $result['conditions'][0]);
    }

    /**
     * Test where() with comma-separated fields and unsupported operator throws exception.
     */
    public function testWhereWithCommaSeparatedFieldsUnsupportedOperator(): void
    {
        $this->expectException(InvalidFilterException::class);

        // ,= operator doesn't support multiple fields
        $this->builder->where(['name,email,=' => 'test']);
    }

    /**
     * Test where() with comma-separated fields without operator throws exception.
     */
    public function testWhereWithCommaSeparatedFieldsWithoutOperator(): void
    {
        $this->expectException(InvalidFilterException::class);

        $this->builder->where(['name,email' => 'test']);
    }

    /**
     * Test whereCmp() with unknown operator throws exception during build.
     * Note: Exception is thrown in buildCmp() during build(), not in whereCmp().
     */
    public function testWhereCmpWithUnknownOperator(): void
    {
        $this->expectException(UnknownOperatorException::class);
        $this->expectExceptionMessage('Unknown query operator');

        $this->builder->whereCmp('age', '@@', 18);
        // Exception is thrown here, not in whereCmp()
        $this->builder->build();
    }

    /**
     * Test where() with invalid filter format throws exception.
     */
    public function testWhereWithInvalidFilterFormat(): void
    {
        $this->expectException(InvalidFilterException::class);

        // Invalid filter format that doesn't match regex
        $this->builder->where(['@#$%' => 'value']);
    }

    /**
     * Test where() with array value that is not empty and has index 0.
     */
    public function testWhereWithArrayValueHasIndex0(): void
    {
        $this->builder->where(['status' => [0 => 'active', 1 => 'pending']]);
        $result = $this->builder->build();

        $this->assertCount(1, $result['conditions']);
        $this->assertStringContainsString('IN', $result['conditions'][0]);
    }

    /**
     * Test where() parses mixed filter shapes and skips blank optional filters.
     */
    public function testWhereParsesMixedFilterShapes(): void
    {
        $this->builder->where([
            0 => 'archived = 0',
            'ids' => [1, 2],
            'status=' => ['active', 'pending'],
            'role!=' => ['admin', 'editor'],
            'age~=' => [18, 65],
            'score%=' => [3, 1],
            'labels,=' => 'vip',
            'first_name,last_name*=' => 'john',
            'given_name,family_name^=' => 'admin',
            'email_local,email_domain$=' => '.com',
            'skip_first,skip_last?=' => '   ',
            'skip_field?=' => null,
        ]);

        $result = $this->builder->build();

        $this->assertSame(
            [
                'archived = 0',
                '[ids] IN (1, 2)',
                '[status] IN (:status_in_0, :status_in_1)',
                '[role] NOT IN (:role_not_in_0, :role_not_in_1)',
                '[age] BETWEEN :age_min AND :age_max',
                '[score]%3=1',
                '[labels]=:labels',
                '[first_name] LIKE :first_name OR [last_name] LIKE :last_name',
                '[given_name] LIKE :given_name OR [family_name] LIKE :family_name',
                '[email_local] LIKE :email_local OR [email_domain] LIKE :email_domain',
            ],
            $result['conditions']
        );

        $this->assertSame('active', $result['bind']['status_in_0']);
        $this->assertSame('pending', $result['bind']['status_in_1']);
        $this->assertSame('admin', $result['bind']['role_not_in_0']);
        $this->assertSame('editor', $result['bind']['role_not_in_1']);
        $this->assertSame(18, $result['bind']['age_min']);
        $this->assertSame(65, $result['bind']['age_max']);
        $this->assertSame('vip', $result['bind']['labels']);
        $this->assertSame('%john%', $result['bind']['first_name']);
        $this->assertSame('%john%', $result['bind']['last_name']);
        $this->assertSame('admin%', $result['bind']['given_name']);
        $this->assertSame('admin%', $result['bind']['family_name']);
        $this->assertSame('%.com', $result['bind']['email_local']);
        $this->assertSame('%.com', $result['bind']['email_domain']);
    }

    /**
     * Test where() with an associative array without an operator suffix is skipped.
     */
    public function testWhereSkipsAssociativeArrayWithoutOperatorSuffix(): void
    {
        $this->builder->where([
            'meta' => ['type' => 'user'],
        ]);
        $result = $this->builder->build();

        $this->assertSame([], $result['conditions']);
        $this->assertSame([], $result['bind']);
    }

    public function testWhereRangeFilterRequiresExactlyTwoValues(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->builder->where(['age~=' => [18, 30, 40]]);
    }

    public function testWhereArraySuffixWithUnsupportedOperatorRaises(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->builder->where(['score@=' => [1, 2]]);
    }

    public function testWhereSingleFieldStartsWithEndsWithContainsAndInset(): void
    {
        $this->builder->where([
            'name^=' => 'admin',
            'email$=' => '.com',
            'bio*=' => 'php',
            'role,=' => 'vip',
        ]);
        $result = $this->builder->build();

        $this->assertCount(4, $result['conditions']);
        $this->assertSame('admin%', $result['bind']['name']);
        $this->assertSame('%.com', $result['bind']['email']);
        $this->assertSame('%php%', $result['bind']['bio']);
        $this->assertSame('vip', $result['bind']['role']);
    }

    public function testWhereMultiFieldComparisonUsesCmpWhenOnlyOneField(): void
    {
        $this->builder->where(['age>=' => 21]);
        $result = $this->builder->build();

        $this->assertSame(['[age]>=:age'], $result['conditions']);
        $this->assertSame(21, $result['bind']['age']);
    }

    public function testWhereSingleFieldUnknownOperatorRaises(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->builder->where(['status@@' => 'x']);
    }
}
