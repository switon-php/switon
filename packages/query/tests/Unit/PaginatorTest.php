<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use Switon\Query\Paginator;
use Switon\Query\Tests\TestCase;
use DivisionByZeroError;

/**
 * Test cases for Paginator class.
 *
 * @group query
 */
class PaginatorTest extends TestCase
{
    /**
     * Test that paginate() calculates pagination correctly.
     */
    public function testPaginate(): void
    {
        $paginator = new Paginator();
        $paginator->paginate(100, 1, 10);
        $paginator->items = [];

        $this->assertEquals(100, $paginator->count);
        $this->assertEquals(1, $paginator->page);
        $this->assertEquals(10, $paginator->size);
        $this->assertEquals(10, $paginator->pages);
        $this->assertEquals(-1, $paginator->prev);
        $this->assertEquals(2, $paginator->next);
    }

    /**
     * Test that paginate() calculates pages correctly with remainder.
     */
    public function testPaginateWithRemainder(): void
    {
        $paginator = new Paginator();
        $paginator->paginate(95, 1, 10);
        $paginator->items = [];

        $this->assertEquals(95, $paginator->count);
        $this->assertEquals(10, $paginator->pages);
    }

    /**
     * Test that prev is -1 on first page.
     */
    public function testPrevOnFirstPage(): void
    {
        $paginator = new Paginator();
        $paginator->paginate(100, 1, 10);
        $paginator->items = [];

        $this->assertEquals(-1, $paginator->prev);
    }

    /**
     * Test that next is -1 on last page.
     */
    public function testNextOnLastPage(): void
    {
        $paginator = new Paginator();
        $paginator->paginate(100, 10, 10);
        $paginator->items = [];

        $this->assertEquals(-1, $paginator->next);
    }

    /**
     * Test that prev and next are calculated correctly on middle page.
     */
    public function testPrevNextOnMiddlePage(): void
    {
        $paginator = new Paginator();
        $paginator->paginate(100, 5, 10);
        $paginator->items = [];

        $this->assertEquals(4, $paginator->prev);
        $this->assertEquals(6, $paginator->next);
    }

    /**
     * Test that toArray() returns correct structure.
     */
    public function testToArray(): void
    {
        $paginator = new Paginator();
        $paginator->paginate(100, 1, 10);
        $paginator->items = [1, 2, 3];

        $array = $paginator->toArray();

        $this->assertArrayHasKey('page', $array);
        $this->assertArrayHasKey('size', $array);
        $this->assertArrayHasKey('count', $array);
        $this->assertArrayHasKey('pages', $array);
        $this->assertArrayHasKey('items', $array);
        $this->assertEquals(1, $array['page']);
        $this->assertEquals(10, $array['size']);
        $this->assertEquals(100, $array['count']);
        $this->assertEquals(10, $array['pages']);
        $this->assertEquals([1, 2, 3], $array['items']);
    }

    /**
     * Test that jsonSerialize() returns same structure as toArray().
     */
    public function testJsonSerialize(): void
    {
        $paginator = new Paginator();
        $paginator->paginate(100, 1, 10);
        $paginator->items = [1, 2, 3];

        $json = $paginator->jsonSerialize();

        $this->assertEquals($paginator->toArray(), $json);
    }

    /**
     * Test that __toString() returns JSON string.
     */
    public function testToString(): void
    {
        $paginator = new Paginator();
        $paginator->paginate(100, 1, 10);
        $paginator->items = [1, 2, 3];

        $json = (string)$paginator;

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertEquals(1, $decoded['page']);
        $this->assertEquals(10, $decoded['size']);
        $this->assertEquals(100, $decoded['count']);
        $this->assertEquals(10, $decoded['pages']);
    }

    /**
     * Test pagination with zero count.
     */
    public function testPaginateWithZeroCount(): void
    {
        $paginator = new Paginator();
        $paginator->paginate(0, 1, 10);
        $paginator->items = [];

        $this->assertEquals(0, $paginator->count);
        $this->assertEquals(0, $paginator->pages);
        $this->assertEquals(-1, $paginator->prev);
        $this->assertEquals(-1, $paginator->next);
    }

    /**
     * Test pagination with count less than size.
     */
    public function testPaginateWithCountLessThanSize(): void
    {
        $paginator = new Paginator();
        $paginator->paginate(5, 1, 10);
        $paginator->items = [];

        $this->assertEquals(5, $paginator->count);
        $this->assertEquals(1, $paginator->pages);
        $this->assertEquals(-1, $paginator->prev);
        $this->assertEquals(-1, $paginator->next);
    }

    /**
     * Test that setItems returns the same instance for fluent chaining.
     */
    public function testSetItemsReturnsSameInstance(): void
    {
        $paginator = new Paginator();
        $paginator->paginate(0, 1, 10);

        $result = $paginator->setItems([7, 8]);

        $this->assertSame($paginator, $result);
        $this->assertSame([7, 8], $paginator->items);
    }

    /**
     * Test pagination with page beyond last page.
     */
    public function testPaginateWithPageBeyondLast(): void
    {
        $paginator = new Paginator();
        $paginator->paginate(100, 15, 10);
        $paginator->items = [];

        // Page 15 is beyond last page (10), so prev should be -1 (no previous page when beyond last)
        // The condition checks: page <= pages && page > 1, so if page > pages, prev = -1
        $this->assertEquals(-1, $paginator->prev);
        $this->assertEquals(-1, $paginator->next);
    }

    public function testPaginateThrowsDivisionByZeroWhenSizeIsZero(): void
    {
        $paginator = new Paginator();

        $this->expectException(DivisionByZeroError::class);

        $paginator->paginate(100, 1, 0);
    }
}
