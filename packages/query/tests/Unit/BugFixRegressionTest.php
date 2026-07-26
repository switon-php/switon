<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use Switon\Query\ConditionBuilder;
use Switon\Query\Paginator;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for bug fixes in the query component.
 *
 * Each test verifies a specific bug fix to prevent regressions.
 */
class BugFixRegressionTest extends TestCase
{
    /**
     * Test that buildCmp ~= operator with non-zero value generates correct bind parameter with colon.
     *
     * Bug: buildCmp generated `[field]=field_name` instead of `[field]=:field_name`
     * when using ~= with non-zero, non-empty values. The `:` prefix was missing.
     */
    public function testBuildCmpTildeEqualsWithNonZeroValueHasColonInBindParam(): void
    {
        // Arrange
        $builder = new ConditionBuilder();

        // Act
        $builder->whereCmp('status', '~=', 'active');
        $result = $builder->build();

        // Assert
        $condition = $result['conditions'][0];
        $this->assertStringContainsString('=:status', $condition, 'Bind parameter must have colon prefix');
        $this->assertArrayHasKey('status', $result['bind']);
        $this->assertEquals('active', $result['bind']['status']);
    }

    /**
     * Test that buildCmp ~= operator with integer non-zero value generates correct bind.
     */
    public function testBuildCmpTildeEqualsWithIntegerValueHasColonInBindParam(): void
    {
        // Arrange
        $builder = new ConditionBuilder();

        // Act
        $builder->whereCmp('level', '~=', 5);
        $result = $builder->build();

        // Assert
        $condition = $result['conditions'][0];
        $this->assertStringContainsString('=:level', $condition, 'Bind parameter must have colon prefix');
        $this->assertArrayHasKey('level', $result['bind']);
        $this->assertEquals(5, $result['bind']['level']);
    }

    /**
     * Test that buildCmp ~= operator still handles zero correctly (no bind param).
     */
    public function testBuildCmpTildeEqualsWithZeroStillWorks(): void
    {
        // Arrange
        $builder = new ConditionBuilder();

        // Act
        $builder->whereCmp('value', '~=', 0);
        $result = $builder->build();

        // Assert
        $condition = $result['conditions'][0];
        $this->assertStringContainsString('IS NULL OR', $condition);
        $this->assertStringContainsString('=0', $condition);
        $this->assertEmpty($result['bind']);
    }

    /**
     * Test that buildCmp ~= operator still handles empty string correctly (no bind param).
     */
    public function testBuildCmpTildeEqualsWithEmptyStringStillWorks(): void
    {
        // Arrange
        $builder = new ConditionBuilder();

        // Act
        $builder->whereCmp('value', '~=', '');
        $result = $builder->build();

        // Assert
        $condition = $result['conditions'][0];
        $this->assertStringContainsString('IS NULL OR', $condition);
        $this->assertStringContainsString("=''", $condition);
        $this->assertEmpty($result['bind']);
    }

    /**
     * Test that buildCmp uses str_contains instead of strpos for dotted fields.
     *
     * Bug: strpos returns 0 (falsy) when dot is at position 0, so fields starting
     * with dot would not get proper bind key transformation.
     */
    public function testBuildCmpWithDottedFieldGeneratesCorrectBindKey(): void
    {
        // Arrange
        $builder = new ConditionBuilder();

        // Act
        $builder->whereCmp('t.status', '>', 0);
        $result = $builder->build();

        // Assert - dotted field should have underscore bind key
        $this->assertArrayHasKey('t_status', $result['bind'], 'Dotted field should use underscore in bind key');
        $this->assertEquals(0, $result['bind']['t_status']);
    }

    /**
     * Test that buildCmp with ~= and dotted field generates correct bind key.
     */
    public function testBuildCmpTildeEqualsWithDottedFieldGeneratesCorrectBindKey(): void
    {
        // Arrange
        $builder = new ConditionBuilder();

        // Act
        $builder->whereCmp('t.status', '~=', 'active');
        $result = $builder->build();

        // Assert
        $this->assertArrayHasKey('t_status', $result['bind'], 'Dotted field should use underscore in bind key');
        $this->assertEquals('active', $result['bind']['t_status']);
        $this->assertStringContainsString('=:t_status', $result['conditions'][0]);
    }

    /**
     * Test that Paginator setItems() works via interface method.
     *
     * Bug: AbstractQuery::paginate() set $paginator->items directly on the concrete class,
     * bypassing the interface. Now uses setItems() defined in PaginatorInterface.
     */
    public function testPaginatorSetItemsViaInterfaceMethod(): void
    {
        // Arrange
        $paginator = new Paginator();
        $items = [['id' => 1], ['id' => 2]];

        // Act
        $result = $paginator->setItems($items);

        // Assert
        $this->assertSame($paginator, $result, 'setItems() should return $this for fluent interface');
        $this->assertEquals($items, $paginator->items);
    }

    /**
     * Test that Paginator setItems() and paginate() work together correctly.
     */
    public function testPaginatorSetItemsThenPaginate(): void
    {
        // Arrange
        $paginator = new Paginator();
        $items = [['id' => 1], ['id' => 2], ['id' => 3]];

        // Act
        $paginator->setItems($items);
        $paginator->paginate(10, 1, 5);
        $array = $paginator->toArray();

        // Assert
        $this->assertEquals(1, $array['page']);
        $this->assertEquals(5, $array['size']);
        $this->assertEquals(10, $array['count']);
        $this->assertEquals(2, $array['pages']);
        $this->assertEquals($items, $array['items']);
    }
}
