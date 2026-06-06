<?php

declare(strict_types=1);

namespace Switon\Query;

use Switon\Sharding\ShardingManagerInterface;

use function strpos;
use function substr;

/**
 * Plans shard routing for a query (main table + joins).
 */
class ShardRouting implements ShardRoutingInterface
{
    public function __construct(
        protected ShardingManagerInterface       $shardingManager,
        protected ShardingContextInterface       $shardingContext,
        protected ShardingJoinValidatorInterface $shardingJoinValidator,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $predicates Parsed predicate IR from Query.
     * @param list<array{0: Table|string, 1: string|null, 2: string|null, 3: string|null}> $joins Join definitions
     *
     * @return array<string, array<int, string>> Shards in format: [connection => [table1, table2, ...]]
     */
    public function plan(Table $table, array $predicates, array $joins): array
    {
        $mapping = $this->buildJoinMapping($joins);

        $context = $this->shardingContext->extract($predicates);
        $mainContext = $this->normalizeContextForMainTable($context, $joins);

        $shards = $this->shardingManager->multiple($table->connection, $table->table, $mainContext);

        if ($mapping['shardingJoins'] !== []) {
            $joinTableResolver = $this->createJoinTableResolver($mapping['joinTableMap']);
            $this->shardingJoinValidator->validate($shards, $context, $mapping['shardingJoins'], $joinTableResolver, $table->connection);
        }

        return $shards;
    }

    /**
     * @param list<array{0: Table|string, 1: string|null, 2: string|null, 3: string|null}> $joins Join definitions: [Table, condition, alias, type]
     *
     * @return array{shardingJoins: list<array{0: string, 1: string|null, 2: string|null, 3: string|null}>, joinTableMap: array<string, Table>}
     */
    protected function buildJoinMapping(array $joins): array
    {
        $shardingJoins = [];
        $joinTableMap = [];

        foreach ($joins as $join) {
            /** @var Table $joinTable */
            $joinTable = $join[0];
            $shardingJoins[] = [$joinTable->table, $join[1], $join[2], $join[3]];
            $joinTableMap[$joinTable->table] = $joinTable;
        }

        return [
            'shardingJoins' => $shardingJoins,
            'joinTableMap' => $joinTableMap,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array{0: Table|string, 1: string|null, 2: string|null, 3: string|null}> $joins Join definitions: [Table, condition, alias, type]
     *
     * @return array<string, mixed>
     */
    protected function normalizeContextForMainTable(array $context, array $joins): array
    {
        if (empty($joins)) {
            return $context;
        }

        $mainContext = $context;
        foreach ($context as $k => $v) {
            if (($pos = strpos($k, '.')) !== false) {
                $mainContext[substr($k, $pos + 1)] = $v;
            }
        }

        return $mainContext;
    }

    /**
     * @param array<string, Table> $joinTableMap
     *
     * @return callable|null Callable signature: function(string $joinTableName, array $context): array|null
     */
    protected function createJoinTableResolver(array $joinTableMap): ?callable
    {
        if ($joinTableMap === []) {
            return null;
        }

        $shardingManager = $this->shardingManager;

        return static function (string $joinTableName, array $context) use ($joinTableMap, $shardingManager) {
            if (!isset($joinTableMap[$joinTableName])) {
                return null;
            }

            $joinTable = $joinTableMap[$joinTableName];
            return $shardingManager->multiple($joinTable->connection, $joinTable->table, $context);
        };
    }
}
