<?php

declare(strict_types=1);

namespace Switon\Core\Tests\Unit;

use Switon\Core\Set;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Switon\Core\Set
 */
class SetTest extends TestCase
{
    public function testOfCreatesFromVariadicItems(): void
    {
        $set = Set::of('a', 'b', 'a');

        $this->assertSame(['a', 'b'], $set->all());
        $this->assertTrue(Set::of()->isEmpty());
    }

    public function testOfExpandsArrayArguments(): void
    {
        $set = Set::of(['a', 'b'], 'c', ['b', 'd']);

        $this->assertSame(['a', 'b', 'c', 'd'], $set->all());
        $this->assertSame(['x', 'y'], Set::of(['x', 'y'])->all());
    }

    public function testAddHasRemoveAndAll(): void
    {
        $set = new Set(['a', 'b']);

        $this->assertTrue($set->has('a'));
        $this->assertTrue($set->has('b'));
        $this->assertFalse($set->has('c'));
        $this->assertSame(['a', 'b'], $set->all());

        $set->add('c');
        $set->add('a');
        $this->assertSame(['a', 'b', 'c'], $set->all());

        $set->remove('b');
        $this->assertFalse($set->has('b'));
        $this->assertSame(['a', 'c'], $set->all());
    }

    public function testCountClearIsEmptyAndToArray(): void
    {
        $set = new Set([1, 2]);

        $this->assertSame(2, $set->count());
        $this->assertFalse($set->isEmpty());
        $this->assertSame([1, 2], $set->toArray());

        $set->clear();
        $this->assertTrue($set->isEmpty());
        $this->assertSame(0, $set->count());
        $this->assertSame([], $set->all());
    }

    public function testIterationYieldsMembers(): void
    {
        $set = new Set(['x', 'y']);
        $seen = [];
        foreach ($set as $item) {
            $seen[] = $item;
        }

        $this->assertSame(['x', 'y'], $seen);
    }
}
