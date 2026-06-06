<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Query\ShardingContext;

class ShardingContextTest extends TestCase
{
    public function testExtractCollectsEqAndInPredicatesOnly(): void
    {
        $context = (new ShardingContext())->extract([
            ['type' => 'eq', 'field' => 'tenant_id', 'value' => 42],
            ['type' => 'in', 'field' => 'user_id', 'values' => [1, 2, 3]],
            ['type' => 'cmp', 'field' => 'age', 'operator' => '>', 'value' => 18],
            ['type' => 'eq', 'field' => 'missing_value'],
            ['type' => 'in', 'field' => 'bad_values', 'values' => 'not-array'],
            ['type' => 'eq', 'value' => 'no-field'],
        ]);

        $this->assertSame(
            [
                'tenant_id' => 42,
                'user_id' => [1, 2, 3],
            ],
            $context
        );
    }
}
