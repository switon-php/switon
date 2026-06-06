<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Core\Exception\MisuseException;
use Switon\Query\ShardRoutingInterface;
use Switon\Query\Table;
use Switon\Sharding\Exception\ShardingTooManyException;

class AbstractQueryProbeTest extends TestCase
{
    public function testNormalizeShardingContextReturnsOriginalContextWithoutJoins(): void
    {
        $query = new AbstractQueryProbe();

        $context = ['o.tenant_id' => 99, 'region' => 'apac'];

        $this->assertSame($context, $query->exposeNormalizeShardingContext($context, []));
    }

    public function testNormalizeShardingContextAddsUnprefixedKeysWhenJoinsExist(): void
    {
        $query = new AbstractQueryProbe();

        $context = ['o.tenant_id' => 99, 'o.region' => 'apac'];

        $this->assertSame(
            [
                'o.tenant_id' => 99,
                'o.region' => 'apac',
                'tenant_id' => 99,
                'region' => 'apac',
            ],
            $query->exposeNormalizeShardingContext($context, [['orders', null, null, null]])
        );
    }

    public function testGetShardsThrowsMisuseExceptionWhenTableIsMissing(): void
    {
        $query = new AbstractQueryProbe();

        $this->expectException(MisuseException::class);
        $query->exposeGetShards();
    }

    public function testGetShardsDelegatesToShardRouting(): void
    {
        $routing = $this->createMock(ShardRoutingInterface::class);
        $query = new AbstractQueryProbe();
        $query->setShardRouting($routing);
        $query->setTable(Table::of('orders', 'tenant_a'));
        $query->whereEq('tenant_id', 7);

        $routing->expects($this->once())
            ->method('plan')
            ->with(
                $this->callback(static function (Table $table): bool {
                    return $table->table === 'orders' && $table->connection === 'tenant_a';
                }),
                [['type' => 'eq', 'field' => 'tenant_id', 'value' => 7]],
                []
            )
            ->willReturn(['tenant_a' => ['orders_7']]);

        $this->assertSame(['tenant_a' => ['orders_7']], $query->exposeGetShards());
    }

    public function testGetUniqueShardReturnsTableForSingleShard(): void
    {
        $routing = $this->createStub(ShardRoutingInterface::class);
        $query = new AbstractQueryProbe();
        $query->setShardRouting($routing);
        $query->setTable(Table::of('orders', 'tenant_a'));

        $routing->method('plan')->willReturn(['tenant_a' => ['orders_7']]);

        $table = $query->exposeGetUniqueShard();

        $this->assertSame('orders_7', $table->table);
        $this->assertSame('tenant_a', $table->connection);
    }

    public function testGetUniqueShardThrowsExceptionForMultipleDatabases(): void
    {
        $routing = $this->createStub(ShardRoutingInterface::class);
        $query = new AbstractQueryProbe();
        $query->setShardRouting($routing);
        $query->setTable(Table::of('orders', 'tenant_a'));

        $routing->method('plan')->willReturn([
            'tenant_a' => ['orders_7'],
            'tenant_b' => ['orders_8'],
        ]);

        $this->expectException(ShardingTooManyException::class);
        $query->exposeGetUniqueShard();
    }

    public function testGetUniqueShardThrowsExceptionForMultipleTables(): void
    {
        $routing = $this->createStub(ShardRoutingInterface::class);
        $query = new AbstractQueryProbe();
        $query->setShardRouting($routing);
        $query->setTable(Table::of('orders', 'tenant_a'));

        $routing->method('plan')->willReturn([
            'tenant_a' => ['orders_7', 'orders_8'],
        ]);

        $this->expectException(ShardingTooManyException::class);
        $query->exposeGetUniqueShard();
    }
}

final class AbstractQueryProbe extends \Switon\Query\Query
{
    public function setShardRouting(ShardRoutingInterface $shardRouting): void
    {
        $this->shardRouting = $shardRouting;
    }

    public function exposeNormalizeShardingContext(array $context, array $joins): array
    {
        return $this->normalizeShardingContext($context, $joins);
    }

    public function exposeGetShards(): array
    {
        return $this->getShards();
    }

    public function exposeGetUniqueShard(): Table
    {
        return $this->getUniqueShard();
    }
}
