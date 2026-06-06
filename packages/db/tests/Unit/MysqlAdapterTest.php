<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Unit;

use PDO;
use Switon\Db\Connection\Adapter\Mysql;
use Switon\Db\Tests\TestCase;
use Switon\Db\UpsertExpression;

/**
 * Unit tests for MySQL adapter.
 *
 * Focuses on SQL string generation behavior that can be tested without a real database.
 *
 * @group mysql
 */
class MysqlAdapterTest extends TestCase
{
    public function testAllocateIdsAllocatesRangeAndExecutesAlterTable(): void
    {
        // Arrange
        $adapter = new class () extends Mysql {
            public int $queryCalls = 0;
            public array $executedSql = [];

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->queryCalls++;
                if ($this->queryCalls === 1) {
                    // Step 1 query
                    return [[
                        'max_id' => 10,
                        'auto_increment' => 12,
                    ]];
                }

                // Step 4 verify MAX(id)
                return [[
                    'max_id' => 10,
                ]];
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                $this->executedSql[] = $sql;
                return 0;
            }
        };
        // Act
        [$startId, $endId] = $adapter->allocateIds('users', 'id', 5);
        // Assert

        $this->assertSame(12, $startId);
        $this->assertSame(16, $endId);
        $this->assertCount(1, $adapter->executedSql);
        $this->assertStringContainsString('ALTER TABLE [users] AUTO_INCREMENT = 17', $adapter->executedSql[0]);
    }

    public function testAllocateIdsThrowsWhenAutoIncrementIsZero(): void
    {
        // Arrange
        $adapter = new class () extends Mysql {
            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                return [[
                    'max_id' => 0,
                    'auto_increment' => 0,
                ]];
            }
        };
        // Act & Assert

        $this->expectException(\Switon\Db\Exception::class);
        $adapter->allocateIds('missing_table', 'id', 1);
    }

    public function testAllocateIdsThrowsWhenConcurrentInsertsDetected(): void
    {
        $adapter = new class () extends Mysql {
            public int $queryCalls = 0;

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->queryCalls++;
                if ($this->queryCalls === 1) {
                    return [[
                        'max_id' => 10,
                        'auto_increment' => 11,
                    ]];
                }

                // concurrent insert happened
                return [[
                    'max_id' => 11,
                ]];
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                return 0;
            }
        };

        $this->expectException(\Switon\Db\Exception::class);
        $adapter->allocateIds('users', 'id', 5);
    }

    public function testGetMetadataParsesPrimaryKeyAutoIncrementAndIntTypes(): void
    {
        $adapter = new class () extends Mysql {
            public string $lastSql = '';
            public int $lastMode = 0;

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastSql = $sql;
                $this->lastMode = $mode;

                // DESCRIBE result as numeric rows (PDO::FETCH_NUM)
                // [Field, Type, Null, Key, Default, Extra]
                return [
                    ['id', 'int(11)', 'NO', 'PRI', null, 'auto_increment'],
                    ['name', 'varchar(255)', 'YES', '', null, ''],
                    ['age', 'bigint', 'YES', '', null, ''],
                ];
            }
        };

        $meta = $adapter->getMetadata('users');

        $this->assertSame('DESCRIBE [users]', $adapter->lastSql);
        $this->assertSame(PDO::FETCH_NUM, $adapter->lastMode);

        $this->assertSame(['id' => 'int(11)', 'name' => 'varchar(255)', 'age' => 'bigint'], $meta[\Switon\Db\Client::METADATA_ATTRIBUTES]);
        $this->assertSame(['id'], $meta[\Switon\Db\Client::METADATA_PRIMARY_KEY]);
        $this->assertSame('id', $meta[\Switon\Db\Client::METADATA_AUTO_INCREMENT_KEY]);
        $this->assertSame(['id', 'age'], $meta[\Switon\Db\Client::METADATA_INT_TYPE_ATTRIBUTES]);
    }

    public function testGetMetadataHandlesCompositePrimaryKeysAndNoAutoIncrement(): void
    {
        $adapter = new class () extends Mysql {
            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                return [
                    ['user_id', 'int(11)', 'NO', 'PRI', null, ''],
                    ['role_id', 'int(11)', 'NO', 'PRI', null, ''],
                    ['note', 'text', 'YES', '', null, ''],
                ];
            }
        };

        $meta = $adapter->getMetadata('user_roles');

        $this->assertSame(['user_id', 'role_id'], $meta[\Switon\Db\Client::METADATA_PRIMARY_KEY]);
        $this->assertNull($meta[\Switon\Db\Client::METADATA_AUTO_INCREMENT_KEY]);
        $this->assertSame(['user_id', 'role_id'], $meta[\Switon\Db\Client::METADATA_INT_TYPE_ATTRIBUTES]);
    }

    public function testGetMetadataIntTypesDetectsSubstringInt(): void
    {
        $adapter = new class () extends Mysql {
            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                return [
                    ['value', 'tinyint(1)', 'YES', '', null, ''],
                    ['other', 'decimal(10,2)', 'YES', '', null, ''],
                ];
            }
        };

        $meta = $adapter->getMetadata('metrics');

        $this->assertSame(['value'], $meta[\Switon\Db\Client::METADATA_INT_TYPE_ATTRIBUTES]);
    }

    public function testTruncateAndDropUseEscapeIdentifier(): void
    {
        $adapter = new class () extends Mysql {
            public array $executed = [];

            public function __construct()
            {
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                $this->executed[] = $sql;
                return 0;
            }
        };

        $adapter->truncate('users');
        $adapter->drop('users');

        $this->assertSame('TRUNCATE TABLE [users]', $adapter->executed[0]);
        $this->assertSame('DROP TABLE IF EXISTS [users]', $adapter->executed[1]);
    }

    public function testConvertQuotesReplacesBracketIdentifiersWithBackticks(): void
    {
        $adapter = new class () extends Mysql {
            public function __construct()
            {
            }
        };

        $sql = 'SELECT * FROM [users] WHERE [id] = :id';
        $converted = $adapter->convertQuotes($sql);

        $this->assertSame('SELECT * FROM `users` WHERE `id` = :id', $converted);
    }

    public function testConvertQuotesLeavesSqlUnchangedWhenNoBrackets(): void
    {
        $adapter = new class () extends Mysql {
            public function __construct()
            {
            }
        };

        $sql = 'SELECT * FROM users';
        $this->assertSame($sql, $adapter->convertQuotes($sql));
    }

    public function testGetTablesWithSchemaUsesBracketNotationWithoutBacktickWrapping(): void
    {
        $adapter = new class () extends Mysql {
            public string $lastSql = '';

            public function __construct()
            {
                // Intentionally bypass parent constructor (requires fully configured URI/DSN).
                // getTables() does not depend on an active PDO connection.
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastSql = $sql;
                return [];
            }
        };

        $adapter->getTables('mydb');

        $this->assertSame(
            'SHOW FULL TABLES FROM [mydb] WHERE Table_Type != "VIEW"',
            $adapter->lastSql,
            'getTables() should build SQL using bracket notation and rely on convertQuotes() to convert it per adapter'
        );
    }

    public function testGetTablesWithoutSchemaUsesExpectedSql(): void
    {
        $adapter = new class () extends Mysql {
            public string $lastSql = '';

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastSql = $sql;
                return [];
            }
        };

        $adapter->getTables(null);

        $this->assertSame(
            'SHOW FULL TABLES WHERE Table_Type != "VIEW"',
            $adapter->lastSql
        );
    }

    public function testTableExistsWithSchemaBuildsInformationSchemaQueryWithSchemaBind(): void
    {
        $adapter = new class () extends Mysql {
            public string $lastSql = '';
            public array $lastBind = [];

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastSql = $sql;
                $this->lastBind = $bind;
                return [[1]];
            }
        };

        $exists = $adapter->tableExists('mydb.users');

        $this->assertTrue($exists);
        $this->assertStringContainsString('INFORMATION_SCHEMA', $adapter->lastSql);
        $this->assertSame(['table' => 'users', 'schema' => 'mydb'], $adapter->lastBind);
    }

    public function testTableExistsStripsBracketsAndBackticks(): void
    {
        $adapter = new class () extends Mysql {
            public array $lastBind = [];

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastBind = $bind;
                return [[1]];
            }
        };

        $adapter->tableExists('[mydb].[users]');

        $this->assertSame(['table' => 'users', 'schema' => 'mydb'], $adapter->lastBind);

        $adapter->tableExists('`users`');
        $this->assertSame(['table' => 'users'], $adapter->lastBind);
    }

    public function testTableExistsWithoutSchemaUsesDatabaseFunctionAndReturnsFalseWhenQueryEmpty(): void
    {
        $adapter = new class () extends Mysql {
            public string $lastSql = '';
            public array $lastBind = [];
            public int $lastMode = 0;

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastSql = $sql;
                $this->lastBind = $bind;
                $this->lastMode = $mode;
                return [];
            }
        };

        $this->assertFalse($adapter->tableExists('users'));
        $this->assertSame(['table' => 'users'], $adapter->lastBind);
        $this->assertSame(PDO::FETCH_NUM, $adapter->lastMode);
        $this->assertStringContainsString('TABLE_SCHEMA` = DATABASE()', $adapter->lastSql);
    }

    public function testTableExistsReturnsFalseWhenCountIsZero(): void
    {
        $adapter = new class () extends Mysql {
            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                return [[0]];
            }
        };

        $this->assertFalse($adapter->tableExists('users'));
    }

    public function testUpsertBuildsOnDuplicateKeyUpdateSqlWithBindAliases(): void
    {
        $adapter = new class () extends Mysql {
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
                // Non-string values are bound with _dku suffix.
                'name' => 2,
                // int key means "copy from insertFieldValues"
                0 => 'hits',
            ],
            'id'
        );

        $this->assertSame(1, $count);
        $this->assertStringContainsString('INSERT INTO `users`', $adapter->lastSql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $adapter->lastSql);

        $this->assertArrayHasKey('name_dku', $adapter->lastBind);
        $this->assertSame(2, $adapter->lastBind['name_dku']);

        $this->assertArrayHasKey('hits_dku', $adapter->lastBind);
        $this->assertSame(10, $adapter->lastBind['hits_dku']);

        $this->assertSame('John', $adapter->lastBind['name']);
    }

    public function testUpsertBindsAssociativeStringUpdateValues(): void
    {
        $adapter = new class () extends Mysql {
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

        $this->assertStringContainsString('`name`=:name_dku', $adapter->lastSql);
        $this->assertArrayHasKey('name_dku', $adapter->lastBind);
        $this->assertSame('Jane', $adapter->lastBind['name_dku']);
    }

    public function testUpsertUsesExplicitExpressionMarkerForRawSqlExpression(): void
    {
        $adapter = new class () extends Mysql {
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
            [
                'name' => new UpsertExpression('[name]=:name'),
            ],
            'id'
        );

        $this->assertStringContainsString('`name`', $adapter->lastSql);
        $this->assertStringContainsString('`name`=:name', $adapter->lastSql);
        $this->assertArrayNotHasKey('name_dku', $adapter->lastBind);
    }

    public function testUpsertSkipsUpdatingPrimaryKeyAndConvertsExplicitExpressionQuotes(): void
    {
        $adapter = new class () extends Mysql {
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
            ['id' => 1, 'hits' => 10],
            [
                'id' => 999,
                'hits' => new UpsertExpression('[hits]=[hits]+1'),
            ],
            'id'
        );

        $this->assertStringNotContainsString('`id`=:', $adapter->lastSql);
        $this->assertStringContainsString('`hits`=`hits`+1', $adapter->lastSql);
    }

    public function testAllocateIdsUsesMaxIdPlusOneWhenGreaterThanAutoIncrement(): void
    {
        $adapter = new class () extends Mysql {
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
                        'max_id' => 100,
                        'auto_increment' => 50,
                    ]];
                }

                return [[
                    'max_id' => 100,
                ]];
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                $this->executedSql[] = $sql;
                return 0;
            }
        };

        [$startId, $endId] = $adapter->allocateIds('users', 'id', 3);

        $this->assertSame(101, $startId);
        $this->assertSame(103, $endId);
        $this->assertCount(1, $adapter->executedSql);
        // newAI = startId + count
        $this->assertStringContainsString('ALTER TABLE [users] AUTO_INCREMENT = 104', $adapter->executedSql[0]);
    }

    public function testGetTablesUsesFetchNumMode(): void
    {
        $adapter = new class () extends Mysql {
            public int $lastMode = 0;

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastMode = $mode;
                return [
                    ['users', 'BASE TABLE'],
                    ['orders', 'BASE TABLE'],
                ];
            }
        };

        $tables = $adapter->getTables(null);

        $this->assertSame(PDO::FETCH_NUM, $adapter->lastMode);
        $this->assertSame(['users', 'orders'], $tables);
    }
}
