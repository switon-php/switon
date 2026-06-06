<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Query\ShardingJoinValidator;
use Switon\Query\Tests\TestCase;
use Switon\Sharding\Exception\ShardingNotSupportedException;
use Switon\Sharding\ShardingManagerInterface;

final class TestableShardingJoinValidator extends ShardingJoinValidator
{
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }
}

class ShardingJoinValidatorTest extends TestCase
{
    /**
     * Creates a ShardingJoinValidator with dependencies injected via container.
     *
     * Uses a stub event dispatcher for the container itself (to avoid SingletonCreated
     * dispatch interference), then replaces with the test's mock after creation.
     */
    protected function createValidator(
        ShardingManagerInterface $shardingManager,
        EventDispatcherInterface $eventDispatcher
    ): ShardingJoinValidator {
        // Use a stub for container-level event dispatching to avoid SingletonCreated interference
        $containerEventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $container = new \Switon\Testing\Container();
        $container->set(\Switon\Core\ContainerInterface::class, $container);
        $container->set(\Psr\Container\ContainerInterface::class, $container);
        $container->set(ShardingManagerInterface::class, $shardingManager);
        $container->set(EventDispatcherInterface::class, $containerEventDispatcher);
        $container->set(ShardingJoinValidator::class, TestableShardingJoinValidator::class);

        /** @var TestableShardingJoinValidator $validator */
        $validator = $container->get(ShardingJoinValidator::class);
        $validator->setEventDispatcher($eventDispatcher);

        return $validator;
    }

    public function testValidateJoinTablesPasses(): void
    {
        $shardingManager = $this->createStub(ShardingManagerInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $eventDispatcher->expects($this->never())->method('dispatch');

        $validator = $this->createValidator($shardingManager, $eventDispatcher);

        $mainShards = ['db1' => ['users_5']];
        $context = ['user_id' => 5];
        $joins = [['orders', 'users.user_id = orders.user_id', 'o', 'INNER']];
        $joinTableResolver = function (string $joinTable, array $context) {
            return ['db1' => ['orders_5']];
        };

        $validator->validate($mainShards, $context, $joins, $joinTableResolver, 'db1');
    }

    public function testValidateJoinTablesThrowsForCrossConnection(): void
    {
        $shardingManager = $this->createStub(ShardingManagerInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())->method('dispatch');

        $validator = $this->createValidator($shardingManager, $eventDispatcher);

        $mainShards = ['db1' => ['users_5']];
        $context = ['user_id' => 5];
        $joins = [['orders', 'users.user_id = orders.user_id', 'o', 'INNER']];
        $joinTableResolver = function () {
            return ['db2' => ['orders_5']];
        };

        $this->expectException(ShardingNotSupportedException::class);
        $validator->validate($mainShards, $context, $joins, $joinTableResolver, 'db1');
    }

    public function testValidateJoinTablesThrowsForMultipleTables(): void
    {
        $shardingManager = $this->createStub(ShardingManagerInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())->method('dispatch');

        $validator = $this->createValidator($shardingManager, $eventDispatcher);

        $mainShards = ['db1' => ['users_5']];
        $context = ['user_id' => 5];
        $joins = [['orders', 'users.user_id = orders.user_id', 'o', 'INNER']];
        $joinTableResolver = function () {
            return ['db1' => ['orders_5', 'orders_6']];
        };

        $this->expectException(ShardingNotSupportedException::class);
        $validator->validate($mainShards, $context, $joins, $joinTableResolver, 'db1');
    }
}
