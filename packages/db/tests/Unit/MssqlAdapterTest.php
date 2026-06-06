<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Unit;

use PDO;
use Switon\Db\Connection\Adapter\Mssql;
use Switon\Db\Tests\TestCase;
use Switon\Db\UpsertExpression;

/**
 * Unit tests for MSSQL adapter.
 *
 * Tests SQL string generation and query parameter handling.
 *
 * @group mssql
 */
class MssqlAdapterTest extends TestCase
{
    public function testAllocateIdsAllocatesRangeAndReseedsIdentity(): void
    {
        $adapter = new class () extends Mssql {
            public int $queryCalls = 0;
            public array $executedSql = [];

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->queryCalls++;
                if ($this->queryCalls === 1) {
                    return [[
                        'max_id' => 10,
                        'identity_value' => 10,
                    ]];
                }

                return [[
                    'max_id' => 10,
                    'identity_value' => 15,
                ]];
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                $this->executedSql[] = $sql;
                return 0;
            }
        };

        [$startId, $endId] = $adapter->allocateIds('users', 'id', 5);

        $this->assertSame(11, $startId);
        $this->assertSame(15, $endId);
        $this->assertNotEmpty($adapter->executedSql);
        $this->assertStringContainsString('DBCC CHECKIDENT ([users], RESEED, 15)', $adapter->executedSql[0]);
    }

    public function testAllocateIdsThrowsWhenConcurrentInsertsDetected(): void
    {
        $adapter = new class () extends Mssql {
            public int $queryCalls = 0;

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->queryCalls++;
                if ($this->queryCalls === 1) {
                    return [['max_id' => 10, 'identity_value' => 10]];
                }

                return [['max_id' => 11, 'identity_value' => 15]];
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                return 0;
            }
        };

        $this->expectException(\Switon\Db\Exception::class);
        $adapter->allocateIds('users', 'id', 5);
    }

    public function testAllocateIdsThrowsWhenIdentityValueMismatchDetected(): void
    {
        $adapter = new class () extends Mssql {
            public int $queryCalls = 0;

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->queryCalls++;
                if ($this->queryCalls === 1) {
                    return [['max_id' => 10, 'identity_value' => 10]];
                }

                return [['max_id' => 10, 'identity_value' => 14]];
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                return 0;
            }
        };

        $this->expectException(\Switon\Db\Exception::class);
        $adapter->allocateIds('users', 'id', 5);
    }

    public function testGetTablesReturnsTableNameListAndUsesSchemaBindWhenSchemaProvided(): void
    {
        $adapter = new class () extends Mssql {
            public ?string $lastSql = null;
            public array $lastBind = [];
            public ?int $lastMode = null;

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastSql = $sql;
                $this->lastBind = $bind;
                $this->lastMode = $mode;
                return [
                    ['TABLE_NAME' => 'users'],
                    ['TABLE_NAME' => 'orders'],
                ];
            }
        };

        $tables = $adapter->getTables('dbo');

        $this->assertSame(PDO::FETCH_ASSOC, $adapter->lastMode);
        $this->assertSame(['schema' => 'dbo'], $adapter->lastBind);
        $this->assertStringContainsString('INFORMATION_SCHEMA.TABLES', (string)$adapter->lastSql);
        $this->assertSame(['users', 'orders'], $tables);
    }

    public function testTableExistsParsesSchemaTableAndBinds(): void
    {
        $adapter = new class () extends Mssql {
            public array $lastBind = [];

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastBind = $bind;
                return [['1' => 1]];
            }
        };

        $this->assertTrue($adapter->tableExists('[dbo].[users]'));
        $this->assertSame(['table' => 'users', 'schema' => 'dbo'], $adapter->lastBind);

        $this->assertTrue($adapter->tableExists('users'));
        $this->assertSame(['table' => 'users'], $adapter->lastBind);
    }

    public function testTableExistsReturnsFalseWhenQueryReturnsEmpty(): void
    {
        $adapter = new class () extends Mssql {
            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                return [];
            }
        };

        $this->assertFalse($adapter->tableExists('users'));
    }

    public function testBuildSqlThrowsWhenOffsetWithoutOrderBy(): void
    {
        $adapter = new class () extends Mssql {
            public function __construct()
            {
            }
        };

        $this->expectException(\Switon\Core\Exception\PreconditionException::class);
        $adapter->buildSql([
            'fields' => '*',
            'from' => 'users',
            'limit' => 10,
            'offset' => 0,
        ]);
    }

    public function testBuildSqlUsesTopWhenLimitWithoutOffset(): void
    {
        $adapter = new class () extends Mssql {
            public function __construct()
            {
            }
        };

        $sql = $adapter->buildSql([
            'fields' => '*',
            'from' => 'users',
            'limit' => 10,
        ]);

        $this->assertStringContainsString('SELECT TOP 10 *', $sql);
    }

    public function testUpsertBuildsMergeSqlAndRewritesExplicitExpressionsToTargetPrefix(): void
    {
        $adapter = new class () extends Mssql {
            public string $lastSql = '';
            public array $lastBind = [];

            public function __construct()
            {
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                $this->lastSql = $sql;
                $this->lastBind = $bind;
                return 1;
            }
        };

        $count = $adapter->upsert(
            '[users]',
            ['id' => 1, 'name' => 'John', 'hits' => 10],
            [
                'name' => 2,
                0 => 'hits',
                'expr' => new UpsertExpression('[hits]=[hits]+1'),
            ],
            'id'
        );

        $this->assertSame(1, $count);
        $this->assertStringContainsString('MERGE INTO [users] AS target', $adapter->lastSql);
        $this->assertStringContainsString('WHEN MATCHED THEN UPDATE SET', $adapter->lastSql);
        $this->assertStringContainsString('target.[hits]=target.[hits]+1', $adapter->lastSql);

        $this->assertArrayHasKey('name_dku', $adapter->lastBind);
        $this->assertSame(2, $adapter->lastBind['name_dku']);

        $this->assertArrayHasKey('hits_dku', $adapter->lastBind);
        $this->assertSame(10, $adapter->lastBind['hits_dku']);
    }

    public function testUpsertBindsAssociativeStringUpdateValues(): void
    {
        $adapter = new class () extends Mssql {
            public string $lastSql = '';
            public array $lastBind = [];

            public function __construct()
            {
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                $this->lastSql = $sql;
                $this->lastBind = $bind;
                return 1;
            }
        };

        $adapter->upsert(
            '[users]',
            ['id' => 1, 'name' => 'John'],
            ['name' => 'Jane'],
            'id'
        );

        $this->assertStringContainsString('target.[name]=:name_dku', $adapter->lastSql);
        $this->assertArrayHasKey('name_dku', $adapter->lastBind);
        $this->assertSame('Jane', $adapter->lastBind['name_dku']);
    }

    public function testUpsertSkipsUpdatingPrimaryKey(): void
    {
        $adapter = new class () extends Mssql {
            public string $lastSql = '';

            public function __construct()
            {
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                $this->lastSql = $sql;
                return 1;
            }
        };

        $adapter->upsert(
            '[users]',
            ['id' => 1, 'name' => 'John'],
            [
                'id' => 999,
                'name' => 2,
            ],
            'id'
        );

        $this->assertStringNotContainsString('target.[id]=:', $adapter->lastSql);
    }

    public function testGetMetadataParsesPrimaryKeysAndAttributes(): void
    {
        $adapter = new class () extends Mssql {
            public int $queryCalls = 0;
            public array $lastBind = [];

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->queryCalls++;
                $this->lastBind = $bind;

                if ($this->queryCalls === 1) {
                    return [
                        ['COLUMN_NAME' => 'id'],
                    ];
                }

                return [
                    ['COLUMN_NAME' => 'id', 'TYPE_NAME' => 'int identity'],
                    ['COLUMN_NAME' => 'name', 'TYPE_NAME' => 'nvarchar'],
                    ['COLUMN_NAME' => 'age', 'TYPE_NAME' => 'bigint'],
                ];
            }
        };

        $meta = $adapter->getMetadata('dbo.users');

        $this->assertSame(['id'], $meta[\Switon\Db\Client::METADATA_PRIMARY_KEY]);
        $this->assertSame('id', $meta[\Switon\Db\Client::METADATA_AUTO_INCREMENT_KEY]);
        $this->assertContains('id', $meta[\Switon\Db\Client::METADATA_INT_TYPE_ATTRIBUTES]);
        $this->assertContains('age', $meta[\Switon\Db\Client::METADATA_INT_TYPE_ATTRIBUTES]);
    }

    public function testGetMetadataWithoutSchemaUsesTableNameBindOnly(): void
    {
        $adapter = new class () extends Mssql {
            public int $queryCalls = 0;
            public array $binds = [];

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->queryCalls++;
                $this->binds[] = $bind;

                if ($this->queryCalls === 1) {
                    return [
                        ['COLUMN_NAME' => 'id'],
                    ];
                }

                return [
                    ['COLUMN_NAME' => 'id', 'TYPE_NAME' => 'int identity'],
                ];
            }
        };

        $meta = $adapter->getMetadata('users');

        $this->assertSame([
            ['table' => 'users'],
            ['table' => 'users'],
        ], $adapter->binds);

        $this->assertSame(['id'], $meta[\Switon\Db\Client::METADATA_PRIMARY_KEY]);
    }

    /**
     * Test that getTables() with schema parameter passes PDO mode to query().
     *
     * Both code paths (with and without schema) should pass PDO::FETCH_ASSOC
     * to ensure consistent behavior.
     */
    public function testGetTablesWithSchemaPassesPdoMode(): void
    {
        $adapter = new class () extends Mssql {
            public ?int $lastMode = null;

            public function __construct()
            {
                // Bypass parent constructor
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastMode = $mode;
                return [['TABLE_NAME' => 'test_table']];
            }
        };

        // Call with schema parameter
        $adapter->getTables('dbo');

        $this->assertSame(
            PDO::FETCH_ASSOC,
            $adapter->lastMode,
            'getTables() with schema should pass PDO::FETCH_ASSOC to query()'
        );
    }

    /**
     * Test that getTables() without schema parameter passes PDO mode to query().
     */
    public function testGetTablesWithoutSchemaPassesPdoMode(): void
    {
        $adapter = new class () extends Mssql {
            public ?int $lastMode = null;

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastMode = $mode;
                return [['TABLE_NAME' => 'test_table']];
            }
        };

        // Call without schema parameter
        $adapter->getTables();

        $this->assertSame(
            PDO::FETCH_ASSOC,
            $adapter->lastMode,
            'getTables() without schema should pass PDO::FETCH_ASSOC to query()'
        );
    }

    /**
     * Test that buildSql() with GROUP BY, HAVING, and OFFSET generates correct SQL.
     *
     * GROUP BY and HAVING must be inside the subquery when OFFSET pagination is used,
     * not outside where they would cause SQL syntax errors.
     */
    public function testBuildSqlWithGroupByHavingAndOffsetGeneratesCorrectSql(): void
    {
        $adapter = new class () extends Mssql {
            public function __construct()
            {
            }
        };

        $params = [
            'fields' => 'status, COUNT(*) as cnt',
            'from' => 'users',
            'group' => 'status',
            'having' => 'COUNT(*) > 5',
            'order' => 'status',
            'limit' => 10,
            'offset' => 20,
        ];

        $sql = $adapter->buildSql($params);

        // GROUP BY and HAVING should be inside the subquery (before the wrapping)
        // The outer query should not contain GROUP BY or HAVING
        $this->assertStringContainsString('GROUP BY status', $sql);
        $this->assertStringContainsString('HAVING COUNT(*) > 5', $sql);

        // Verify the structure: GROUP BY should appear before the subquery wrapping
        // The SQL should look like: SELECT t.* FROM (SELECT ... GROUP BY ... HAVING ...) as t WHERE ...
        $groupByPos = strpos($sql, 'GROUP BY');
        $fromSubqueryPos = strpos($sql, ') as t WHERE');

        $this->assertNotFalse($groupByPos, 'SQL should contain GROUP BY');
        $this->assertNotFalse($fromSubqueryPos, 'SQL should contain subquery wrapper');
        $this->assertLessThan(
            $fromSubqueryPos,
            $groupByPos,
            'GROUP BY should be inside the subquery (before the closing parenthesis)'
        );
    }

    public function testBuildSqlSupportsDistinctJoinWhereOrderAndForUpdate(): void
    {
        $adapter = new class () extends Mssql {
            public function __construct()
            {
            }
        };

        $sql = $adapter->buildSql([
            'fields' => 'u.*',
            'distinct' => true,
            'from' => 'users u',
            'join' => ' LEFT JOIN orders o ON o.user_id = u.id',
            'where' => 'u.id > 10',
            'group' => 'u.id',
            'having' => 'COUNT(o.id) > 0',
            'order' => 'u.id DESC',
            'forUpdate' => true,
        ]);

        $this->assertStringContainsString('SELECT DISTINCT u.*', $sql);
        $this->assertStringContainsString(' FROM users u', $sql);
        $this->assertStringContainsString('LEFT JOIN orders o', $sql);
        $this->assertStringContainsString(' WHERE u.id > 10', $sql);
        $this->assertStringContainsString(' GROUP BY u.id', $sql);
        $this->assertStringContainsString(' HAVING COUNT(o.id) > 0', $sql);
        $this->assertStringContainsString(' ORDER BY u.id DESC', $sql);
        $this->assertStringContainsString(' FOR UPDATE', $sql);
    }

    public function testConvertQuotesReturnsOriginalSql(): void
    {
        $adapter = new class () extends Mssql {
            public function __construct()
            {
            }
        };

        $sql = 'SELECT * FROM [users] WHERE [id] = :id';
        $this->assertSame($sql, $adapter->convertQuotes($sql));
    }

    public function testTruncateBuildsTruncateTableSql(): void
    {
        $adapter = new class () extends Mssql {
            public string $lastSql = '';

            public function __construct()
            {
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                $this->lastSql = $sql;
                return 1;
            }
        };

        $adapter->truncate('[users]');

        $this->assertSame('TRUNCATE TABLE [users]', $adapter->lastSql);
    }

    public function testDropBuildsDropTableIfExistsSql(): void
    {
        $adapter = new class () extends Mssql {
            public string $lastSql = '';

            public function __construct()
            {
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                $this->lastSql = $sql;
                return 1;
            }
        };

        $adapter->drop('[users]');

        $this->assertSame('DROP TABLE IF EXISTS [users]', $adapter->lastSql);
    }
}
