<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Switon\Query\ShardingContextInterface;
use Switon\Query\ShardingJoinValidatorInterface;
use Switon\Query\ShardRouting;
use Switon\Query\Table;
use Switon\Sharding\ShardingManagerInterface;

class ShardRoutingTest extends TestCase
{
    protected ShardingManagerInterface&MockObject $shardingManager;
    protected ShardingContextInterface&MockObject $shardingContext;
    protected ShardingJoinValidatorInterface&MockObject $joinValidator;
    protected ShardRouting $routing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shardingManager = $this->createMock(ShardingManagerInterface::class);
        $this->shardingContext = $this->createMock(ShardingContextInterface::class);
        $this->joinValidator = $this->createMock(ShardingJoinValidatorInterface::class);

        $this->routing = new ShardRouting(
            $this->shardingManager,
            $this->shardingContext,
            $this->joinValidator
        );
    }

    public function testPlanWithoutJoinsUsesExtractedContextAndSkipsJoinValidation(): void
    {
        $predicates = [['type' => 'eq', 'field' => 'tenant_id', 'value' => 9]];

        $this->shardingContext->expects($this->once())
            ->method('extract')
            ->with($predicates)
            ->willReturn(['tenant_id' => 9]);

        $this->shardingManager->expects($this->once())
            ->method('multiple')
            ->with('default', 'orders', ['tenant_id' => 9])
            ->willReturn(['db0' => ['orders_0']]);

        $this->joinValidator->expects($this->never())->method('validate');

        $result = $this->routing->plan(Table::of('orders', 'default'), $predicates, []);

        $this->assertSame(['db0' => ['orders_0']], $result);
    }

    public function testPlanWithJoinsNormalizesMainContextAndProvidesJoinResolver(): void
    {
        $main = Table::of('orders', 'default');
        $joins = [
            [Table::of('order_items', 'default'), 'orders.id = order_items.order_id', 'oi', 'left'],
        ];
        $predicates = [['type' => 'eq', 'field' => 'o.tenant_id', 'value' => 99]];

        $this->shardingContext->expects($this->once())
            ->method('extract')
            ->with($predicates)
            ->willReturn(['o.tenant_id' => 99]);

        $this->shardingManager->expects($this->exactly(2))
            ->method('multiple')
            ->willReturnCallback(static function (string $connection, string $table, mixed $context): array {
                if ($table === 'orders') {
                    return ['db_main' => ['orders_9']];
                }

                return ['db_join' => ['order_items_9']];
            });

        $capturedResolver = null;
        $this->joinValidator->expects($this->once())
            ->method('validate')
            ->willReturnCallback(static function (
                array     $mainShards,
                array     $context,
                array     $shardingJoins,
                ?callable $joinTableResolver,
                ?string   $connection
            ) use (&$capturedResolver): void {
                self::assertSame(['db_main' => ['orders_9']], $mainShards);
                self::assertSame(['o.tenant_id' => 99], $context);
                self::assertSame([['order_items', 'orders.id = order_items.order_id', 'oi', 'left']], $shardingJoins);
                self::assertSame('default', $connection);
                self::assertNotNull($joinTableResolver);
                $capturedResolver = $joinTableResolver;
            });

        $result = $this->routing->plan($main, $predicates, $joins);

        $this->assertSame(['db_main' => ['orders_9']], $result);
        $this->assertIsCallable($capturedResolver);
        $this->assertSame(['db_join' => ['order_items_9']], $capturedResolver('order_items', ['tenant_id' => 99]));
        $this->assertNull($capturedResolver('missing_table', ['tenant_id' => 99]));
    }
}
