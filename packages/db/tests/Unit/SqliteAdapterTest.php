<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Unit;

use PDO;
use Switon\Db\Client;
use Switon\Db\Connection\Adapter\Sqlite;
use PHPUnit\Framework\TestCase;
use Switon\Db\UpsertExpression;

/**
 * Unit tests for SQLite adapter.
 *
 * Tests SQL string generation and identifier handling behavior.
 */
class SqliteAdapterTest extends TestCase
{
    public function testConvertQuotesReplacesBracketIdentifiersWithBackticks(): void
    {
        $adapter = new class () extends Sqlite {
            public function __construct()
            {
            }
        };

        $sql = 'SELECT * FROM [users] WHERE [id] = :id';
        $this->assertSame('SELECT * FROM `users` WHERE `id` = :id', $adapter->convertQuotes($sql));
    }

    public function testConvertQuotesReturnsOriginalWhenNoBrackets(): void
    {
        $adapter = new class () extends Sqlite {
            public function __construct()
            {
            }
        };

        $sql = 'SELECT * FROM `users` WHERE `id` = :id';
        $this->assertSame($sql, $adapter->convertQuotes($sql));
    }

    public function testGetTablesReturnsTblNameList(): void
    {
        $adapter = new class () extends Sqlite {
            public string $lastSql = '';

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastSql = $sql;
                return [
                    ['tbl_name' => 'users'],
                    ['tbl_name' => 'orders'],
                ];
            }
        };

        $tables = $adapter->getTables();

        $this->assertSame("SELECT tbl_name FROM sqlite_master WHERE type = 'table' ORDER BY tbl_name", $adapter->lastSql);
        $this->assertSame(['users', 'orders'], $tables);
    }

    public function testGetMetadataParsesPrimaryKeyAutoIncrementAndIntTypes(): void
    {
        $adapter = new class () extends Sqlite {
            public string $lastSql = '';

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastSql = $sql;
                // Mimic PRAGMA table_info result
                return [
                    ['name' => 'id', 'type' => 'INTEGER', 'pk' => 1],
                    ['name' => 'name', 'type' => 'TEXT', 'pk' => 0],
                    ['name' => 'age', 'type' => 'INT', 'pk' => 0],
                ];
            }
        };

        $meta = $adapter->getMetadata('users');

        $this->assertStringContainsString('PRAGMA table_info([users])', $adapter->lastSql);

        $this->assertSame(
            ['id' => 'INTEGER', 'name' => 'TEXT', 'age' => 'INT'],
            $meta[Client::METADATA_ATTRIBUTES]
        );
        $this->assertSame(['id'], $meta[Client::METADATA_PRIMARY_KEY]);
        $this->assertSame('id', $meta[Client::METADATA_AUTO_INCREMENT_KEY]);
        $this->assertSame(['id', 'age'], $meta[Client::METADATA_INT_TYPE_ATTRIBUTES]);
    }

    public function testUpsertBuildsOnConflictSqlAndBinds(): void
    {
        $adapter = new class () extends Sqlite {
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
        $this->assertStringContainsString('ON CONFLICT(`id`) DO UPDATE SET', $adapter->lastSql);

        $this->assertArrayHasKey('name_dku', $adapter->lastBind);
        $this->assertSame(2, $adapter->lastBind['name_dku']);

        $this->assertArrayHasKey('hits_dku', $adapter->lastBind);
        $this->assertSame(10, $adapter->lastBind['hits_dku']);
    }

    public function testUpsertBindsAssociativeStringUpdateValues(): void
    {
        $adapter = new class () extends Sqlite {
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

    public function testUpsertSkipsUpdatingPrimaryKeyAndAllowsExplicitExpression(): void
    {
        $adapter = new class () extends Sqlite {
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
            ['id' => 1, 'hits' => 10],
            [
                'id' => 999,
                'hits' => new UpsertExpression('[hits] + 1'),
            ],
            'id'
        );

        $this->assertStringNotContainsString('`id`=:', $adapter->lastSql);
        $this->assertStringContainsString('`hits` + 1', $adapter->lastSql);
        $this->assertArrayNotHasKey('id_dku', $adapter->lastBind);
    }

    /**
     * Test that tableExists() correctly handles schema.table format.
     *
     * SQLite does not support schemas, so when given "schema.table" format,
     * it should use the table name part (second part), not the schema part.
     */
    public function testTableExistsWithSchemaTableFormatUsesTableName(): void
    {
        $adapter = new class () extends Sqlite {
            public string $lastTable = '';

            public function __construct()
            {
                // Bypass parent constructor - tableExists() does not need PDO
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastTable = $bind['table'] ?? '';
                return [[1]]; // Simulate table exists
            }
        };

        // When given "schema.tablename", should query for "tablename" not "schema"
        $adapter->tableExists('myschema.mytable');

        $this->assertSame(
            'mytable',
            $adapter->lastTable,
            'tableExists() should use the table name part when given schema.table format'
        );
    }

    /**
     * Test that tableExists() correctly handles simple table name.
     */
    public function testTableExistsWithSimpleTableName(): void
    {
        $adapter = new class () extends Sqlite {
            public string $lastTable = '';

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastTable = $bind['table'] ?? '';
                return [[1]];
            }
        };

        $adapter->tableExists('users');

        $this->assertSame('users', $adapter->lastTable);
    }

    /**
     * Test that tableExists() strips bracket notation.
     */
    public function testTableExistsStripsBracketNotation(): void
    {
        $adapter = new class () extends Sqlite {
            public string $lastTable = '';

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastTable = $bind['table'] ?? '';
                return [[1]];
            }
        };

        $adapter->tableExists('[users]');

        $this->assertSame('users', $adapter->lastTable);
    }

    public function testTableExistsReturnsFalseWhenQueryReturnsEmpty(): void
    {
        $adapter = new class () extends Sqlite {
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

    public function testTruncateDeletesTableRowsAndResetsSequence(): void
    {
        $adapter = new class () extends Sqlite {
            public array $executed = [];

            public function __construct()
            {
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                $this->executed[] = ['sql' => $sql, 'bind' => $bind];
                return 1;
            }
        };

        $adapter->truncate('[users]');

        $this->assertCount(2, $adapter->executed);
        $this->assertSame('DELETE FROM [users]', $adapter->executed[0]['sql']);
        $this->assertSame('DELETE FROM sqlite_sequence WHERE name=:name', $adapter->executed[1]['sql']);
        $this->assertSame(['name' => '[users]'], $adapter->executed[1]['bind']);
    }

    public function testDropBuildsDropTableIfExistsSql(): void
    {
        $adapter = new class () extends Sqlite {
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

    public function testAllocateIdsThrowsWhenInTransaction(): void
    {
        $adapter = new class () extends Sqlite {
            public function __construct()
            {
            }

            public function setInTransaction(bool $inTransaction): void
            {
                $this->in_transaction = $inTransaction;
            }
        };

        $adapter->setInTransaction(true);

        $this->expectException(\Switon\Db\Exception::class);
        $adapter->allocateIds('users', 'id', 10);
    }

    public function testAllocateIdsInsertsSequenceWhenMissingAndReturnsRange(): void
    {
        $adapter = new class () extends Sqlite {
            public int $queryCalls = 0;
            public array $execCalls = [];

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->queryCalls++;

                // Initial read: no seq row.
                if ($this->queryCalls === 1) {
                    return [['max_id' => 10, 'seq' => null]];
                }

                // Verify read: seq should match newSeq.
                return [['max_id' => 10, 'seq' => 15]];
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                $this->execCalls[] = ['sql' => $sql, 'bind' => $bind];
                return 1;
            }
        };

        $range = $adapter->allocateIds('users', 'id', 5);

        $this->assertSame([11, 15], $range);
        $this->assertCount(1, $adapter->execCalls);
        $this->assertStringContainsString('INSERT INTO sqlite_sequence', $adapter->execCalls[0]['sql']);
        $this->assertSame(['name' => 'users', 'seq' => 15], $adapter->execCalls[0]['bind']);
    }

    public function testAllocateIdsUpdatesSequenceWhenExistsAndReturnsRange(): void
    {
        $adapter = new class () extends Sqlite {
            public int $queryCalls = 0;
            public array $execCalls = [];

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->queryCalls++;

                if ($this->queryCalls === 1) {
                    return [['max_id' => 10, 'seq' => 12]];
                }

                return [['max_id' => 10, 'seq' => 16]];
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                $this->execCalls[] = ['sql' => $sql, 'bind' => $bind];
                return 1;
            }
        };

        $range = $adapter->allocateIds('users', 'id', 4);

        $this->assertSame([13, 16], $range);
        $this->assertCount(1, $adapter->execCalls);
        $this->assertStringContainsString('UPDATE sqlite_sequence', $adapter->execCalls[0]['sql']);
        $this->assertSame(['seq' => 16, 'name' => 'users'], $adapter->execCalls[0]['bind']);
    }

    public function testAllocateIdsThrowsWhenConcurrentInsertDetected(): void
    {
        $adapter = new class () extends Sqlite {
            public int $queryCalls = 0;

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->queryCalls++;

                if ($this->queryCalls === 1) {
                    return [['max_id' => 10, 'seq' => 12]];
                }

                // max_id changed => concurrent insert
                return [['max_id' => 11, 'seq' => 16]];
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                return 1;
            }
        };

        $this->expectException(\Switon\Db\Exception::class);
        $adapter->allocateIds('users', 'id', 4);
    }

    public function testAllocateIdsThrowsWhenSequenceMismatchDetected(): void
    {
        $adapter = new class () extends Sqlite {
            public int $queryCalls = 0;

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->queryCalls++;

                if ($this->queryCalls === 1) {
                    return [['max_id' => 10, 'seq' => 12]];
                }

                // seq mismatch => concurrent allocation
                return [['max_id' => 10, 'seq' => 999]];
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                return 1;
            }
        };

        $this->expectException(\Switon\Db\Exception::class);
        $adapter->allocateIds('users', 'id', 4);
    }
}
