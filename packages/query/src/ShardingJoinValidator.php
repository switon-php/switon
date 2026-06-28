<?php

declare(strict_types=1);

namespace Switon\Query;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Query\Event\ShardValidationFailed;
use Switon\Sharding\Exception\ShardingNotSupportedException;
use Switon\Sharding\ShardingManagerInterface;
use Throwable;

/**
 * Default implementation of <code>ShardingJoinValidatorInterface</code>.
 *
 * Verifies that join targets remain on compatible shard connections and tables,
 * and reports validation failures through sharding events for logging.
 *
 * @see \Switon\Query\ShardingJoinValidatorInterface
 * @see \Switon\Query\Event\ShardValidationFailed
 */
class ShardingJoinValidator implements ShardingJoinValidatorInterface
{
    #[Autowired] protected ShardingManagerInterface $shardingManager;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;

    /**
     * {@inheritDoc}
     *
     * Dispatches <code>ShardValidationFailed</code> before rethrowing the
     *
     * @param array<string, list<string>> $mainShards
     * @param array<string, mixed> $context
     * @param list<array{0: Table|string, 1: string|null, 2: string|null, 3: string|null}> $joins
     * original validation exception.
     */
    public function validate(array $mainShards, array $context, array $joins, ?callable $joinTableResolver = null, ?string $connection = null): void
    {
        try {
            $mainConnections = array_keys($mainShards);

            if (empty($mainConnections)) {
                if ($connection !== null) {
                    $mainConnections = [$connection];
                } else {
                    return;
                }
            }

            foreach ($joins as $join) {
                $joinTable = $join[0];

                if ($joinTableResolver !== null) {
                    $joinShards = $joinTableResolver($joinTable, $context);
                    if ($joinShards === null) {
                        $joinConnection = $connection ?? $mainConnections[0];
                        $joinShards = $this->shardingManager->multiple($joinConnection, $joinTable, $context);
                    }
                } else {
                    $joinConnection = $connection ?? $mainConnections[0];
                    $joinShards = $this->shardingManager->multiple($joinConnection, $joinTable, $context);
                }

                foreach ($mainConnections as $mainConnection) {
                    if (!isset($joinShards[$mainConnection])) {
                        ShardingNotSupportedException::raise('JOIN across different database connections is not supported in sharded queries');
                    }

                    $joinTables = $joinShards[$mainConnection];
                    if (count($joinTables) > 1) {
                        ShardingNotSupportedException::raise('JOIN across different sharded tables is not supported');
                    }
                }
            }
        } catch (Throwable $e) {
            $this->eventDispatcher->dispatch(ShardValidationFailed::from($this, $mainShards, $context, $joins, $e));
            throw $e;
        }
    }
}
