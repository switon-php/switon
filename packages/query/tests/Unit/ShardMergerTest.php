<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Core\Exception\MisuseException;
use Switon\Query\ShardMerger;

class ShardMergerTest extends TestCase
{
    public function testSortSupportsAscAndDescSpecifications(): void
    {
        $rows = [
            ['id' => 2, 'name' => 'beta', 'score' => 8],
            ['id' => 1, 'name' => 'alpha', 'score' => 9],
            ['id' => 3, 'name' => 'alpha', 'score' => 7],
        ];

        $sorted = (new ShardMerger())->sort($rows, ['name' => 'ASC', 'score' => SORT_DESC]);

        $this->assertSame(
            [
                ['id' => 1, 'name' => 'alpha', 'score' => 9],
                ['id' => 3, 'name' => 'alpha', 'score' => 7],
                ['id' => 2, 'name' => 'beta', 'score' => 8],
            ],
            $sorted
        );
    }

    public function testIndexBySupportsFieldMapAndCallbackModes(): void
    {
        $rows = [
            ['id' => 10, 'name' => 'alice', 'score' => 91],
            ['id' => 20, 'name' => 'bob', 'score' => 82],
        ];
        $merger = new ShardMerger();

        $byField = $merger->indexBy($rows, 'id');
        $this->assertSame('alice', $byField[10]['name']);
        $this->assertSame('bob', $byField[20]['name']);

        $mapped = $merger->indexBy($rows, ['id' => 'score']);
        $this->assertSame([10 => 91, 20 => 82], $mapped);

        $byCallback = $merger->indexBy($rows, static fn (array $row): string => 'u' . $row['id']);
        $this->assertSame('alice', $byCallback['u10']['name']);
        $this->assertSame('bob', $byCallback['u20']['name']);
    }

    public function testAggregateSupportsGroupAndAvgComputation(): void
    {
        $rows = [
            ['team' => 'A', 'score' => 7, 'count' => 1, 'avg_sum' => 70, 'avg_count' => 2],
            ['team' => 'A', 'score' => 9, 'count' => 1, 'avg_sum' => 30, 'avg_count' => 1],
            ['team' => 'B', 'score' => 5, 'count' => 1, 'avg_sum' => 50, 'avg_count' => 2],
        ];

        $aggregated = (new ShardMerger())->aggregate(
            $rows,
            [
                'score' => 'MAX',
                'count' => 'SUM',
                'avg' => 'AVG',
            ],
            ['team']
        );

        $this->assertSame(
            [
                ['team' => 'A', 'score' => 9, 'count' => 2, 'avg' => 100 / 3],
                ['team' => 'B', 'score' => 5, 'count' => 1, 'avg' => 25],
            ],
            $aggregated
        );
    }

    public function testAggregateAvgRaisesWhenRequiredSumOrCountFieldMissing(): void
    {
        $rows = [
            ['team' => 'A', 'avg_count' => 2],
        ];

        $this->expectException(MisuseException::class);
        (new ShardMerger())->aggregate($rows, ['avg' => 'AVG'], ['team']);
    }
}
