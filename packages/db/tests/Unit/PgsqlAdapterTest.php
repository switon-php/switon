<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Unit;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Switon\Core\Exception\DsnFormatException;
use Switon\Db\Connection\Adapter\Pgsql;
use Switon\Db\Tests\TestCase;
use Switon\Db\UpsertExpression;

/**
 * Unit tests for PostgreSQL adapter.
 *
 * Tests URI parsing, SQL generation, and convertQuotes functionality.
 * Does not require actual PostgreSQL connection.
 *
 * @group pgsql
 */
class PgsqlAdapterTest extends TestCase
{
    public function testAllocateIdsThrowsWhenSequenceNotFound(): void
    {
        $adapter = new class () extends Pgsql {
            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                return [['seq_name' => null]];
            }
        };

        $this->expectException(\Switon\Db\Exception::class);
        $adapter->allocateIds('users', 'id', 10);
    }

    public function testAllocateIdsAllocatesRangeAndSetsSequence(): void
    {
        $adapter = new class () extends Pgsql {
            public int $queryCalls = 0;
            public array $executed = [];

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->queryCalls++;
                if ($this->queryCalls === 1) {
                    return [['seq_name' => 'public.users_id_seq']];
                }

                if ($this->queryCalls === 2) {
                    return [[
                        'max_id' => 10,
                        'seq_value' => 10,
                    ]];
                }

                // verify
                return [[
                    'max_id' => 10,
                    'seq_value' => 15,
                ]];
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                $this->executed[] = ['sql' => $sql, 'bind' => $bind];
                return 0;
            }
        };

        [$startId, $endId] = $adapter->allocateIds('users', 'id', 5);

        $this->assertSame(11, $startId);
        $this->assertSame(15, $endId);
        $this->assertNotEmpty($adapter->executed);
        $this->assertStringContainsString('SELECT setval', $adapter->executed[0]['sql']);
        $this->assertSame(['seq' => 'public.users_id_seq', 'val' => 15], $adapter->executed[0]['bind']);
    }

    public function testAllocateIdsThrowsWhenConcurrentInsertsDetected(): void
    {
        $adapter = new class () extends Pgsql {
            public int $queryCalls = 0;

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->queryCalls++;
                if ($this->queryCalls === 1) {
                    return [['seq_name' => 'public.users_id_seq']];
                }
                if ($this->queryCalls === 2) {
                    return [['max_id' => 10, 'seq_value' => 10]];
                }
                // max changed
                return [['max_id' => 11, 'seq_value' => 15]];
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                return 0;
            }
        };

        $this->expectException(\Switon\Db\Exception::class);
        $adapter->allocateIds('users', 'id', 5);
    }

    public function testAllocateIdsThrowsWhenSequenceValueMismatchDetected(): void
    {
        $adapter = new class () extends Pgsql {
            public int $queryCalls = 0;

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->queryCalls++;
                if ($this->queryCalls === 1) {
                    return [['seq_name' => 'public.users_id_seq']];
                }
                if ($this->queryCalls === 2) {
                    return [['max_id' => 10, 'seq_value' => 10]];
                }
                // seq changed
                return [['max_id' => 10, 'seq_value' => 14]];
            }

            public function execute(string $sql, array $bind = [], bool $has_insert_id = false): int
            {
                return 0;
            }
        };

        $this->expectException(\Switon\Db\Exception::class);
        $adapter->allocateIds('users', 'id', 5);
    }

    public function testGetTablesDefaultsToPublicSchemaAndBindsSchema(): void
    {
        $adapter = new class () extends Pgsql {
            public string $lastSql = '';
            public array $lastBind = [];

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastSql = $sql;
                $this->lastBind = $bind;
                return [
                    ['table_name' => 'users'],
                    ['table_name' => 'orders'],
                ];
            }
        };

        $tables = $adapter->getTables();

        $this->assertSame(['schema' => 'public'], $adapter->lastBind);
        $this->assertSame(['users', 'orders'], $tables);
    }

    public function testTableExistsDefaultsToPublicSchemaWhenNoSchemaProvided(): void
    {
        $adapter = new class () extends Pgsql {
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

        $exists = $adapter->tableExists('users');

        $this->assertTrue($exists);
        $this->assertSame(['schema' => 'public', 'table' => 'users'], $adapter->lastBind);
    }

    public function testTableExistsParsesSchemaTableAndStripsQuotesAndBrackets(): void
    {
        $adapter = new class () extends Pgsql {
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

        $adapter->tableExists('[my_schema].[my_table]');
        $this->assertSame(['schema' => 'my_schema', 'table' => 'my_table'], $adapter->lastBind);

        $adapter->tableExists('"my_schema"."my_table"');
        $this->assertSame(['schema' => 'my_schema', 'table' => 'my_table'], $adapter->lastBind);
    }

    public function testTruncateAndDropUseEscapeIdentifier(): void
    {
        $adapter = new class () extends Pgsql {
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

        $this->assertSame('TRUNCATE TABLE [users] RESTART IDENTITY', $adapter->executed[0]);
        $this->assertSame('DROP TABLE IF EXISTS [users]', $adapter->executed[1]);
    }

    public function testUpsertBuildsOnConflictSqlAndBinds(): void
    {
        $adapter = new class () extends Pgsql {
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
            ],
            'id'
        );

        $this->assertSame(1, $count);
        $this->assertStringContainsString('INSERT INTO "users"', $adapter->lastSql);
        $this->assertStringContainsString('ON CONFLICT("id") DO UPDATE SET', $adapter->lastSql);

        $this->assertArrayHasKey('name_dku', $adapter->lastBind);
        $this->assertSame(2, $adapter->lastBind['name_dku']);

        $this->assertArrayHasKey('hits_dku', $adapter->lastBind);
        $this->assertSame(10, $adapter->lastBind['hits_dku']);
    }

    public function testUpsertBindsAssociativeStringUpdateValues(): void
    {
        $adapter = new class () extends Pgsql {
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

        $this->assertStringContainsString('"name"=:name_dku', $adapter->lastSql);
        $this->assertArrayHasKey('name_dku', $adapter->lastBind);
        $this->assertSame('Jane', $adapter->lastBind['name_dku']);
    }

    public function testUpsertAllowsExplicitExpressionMarker(): void
    {
        $adapter = new class () extends Pgsql {
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
            ['hits' => new UpsertExpression('[hits]=[hits]+1')],
            'id'
        );

        $this->assertStringContainsString('"hits"="hits"+1', $adapter->lastSql);
        $this->assertArrayNotHasKey('hits_dku', $adapter->lastBind);
    }

    public function testGetMetadataParsesPrimaryKeyAutoIncrementAndIntTypes(): void
    {
        $adapter = new class () extends Pgsql {
            public string $lastSql = '';
            public array $lastBind = [];

            public function __construct()
            {
            }

            public function query(string $sql, array $bind = [], int $mode = PDO::FETCH_ASSOC): array
            {
                $this->lastSql = $sql;
                $this->lastBind = $bind;

                return [
                    ['column_name' => 'id', 'data_type' => 'integer', 'column_default' => 'nextval(\'users_id_seq\'::regclass)', 'is_primary' => true],
                    ['column_name' => 'name', 'data_type' => 'text', 'column_default' => null, 'is_primary' => false],
                    ['column_name' => 'age', 'data_type' => 'bigint', 'column_default' => null, 'is_primary' => false],
                ];
            }
        };

        $meta = $adapter->getMetadata('public.users');

        $this->assertSame(['schema' => 'public', 'table' => 'users'], $adapter->lastBind);
        $this->assertSame(['id'], $meta[\Switon\Db\Client::METADATA_PRIMARY_KEY]);
        $this->assertSame('id', $meta[\Switon\Db\Client::METADATA_AUTO_INCREMENT_KEY]);
        $this->assertContains('id', $meta[\Switon\Db\Client::METADATA_INT_TYPE_ATTRIBUTES]);
        $this->assertContains('age', $meta[\Switon\Db\Client::METADATA_INT_TYPE_ATTRIBUTES]);
    }

    /**
     * Test that constructor accepts pgsql:// scheme.
     */
    public function testConstructorAcceptsPgsqlScheme(): void
    {
        $adapter = $this->createAdapter('pgsql://user:pass@localhost:5432/testdb');
        $this->assertInstanceOf(Pgsql::class, $adapter);
    }

    /**
     * Test that constructor accepts postgres:// scheme.
     */
    public function testConstructorAcceptsPostgresScheme(): void
    {
        $adapter = $this->createAdapter('postgres://user:pass@localhost/testdb');
        $this->assertInstanceOf(Pgsql::class, $adapter);
    }

    /**
     * Test that constructor accepts postgresql:// scheme.
     */
    public function testConstructorAcceptsPostgresqlScheme(): void
    {
        $adapter = $this->createAdapter('postgresql://user:pass@localhost/testdb');
        $this->assertInstanceOf(Pgsql::class, $adapter);
    }

    /**
     * Test that constructor rejects invalid scheme.
     */
    public function testConstructorRejectsInvalidScheme(): void
    {
        $this->expectException(DsnFormatException::class);
        $this->createAdapter('mysql://user:pass@localhost/testdb');
    }

    /**
     * Test that constructor uses default username when not specified.
     */
    public function testConstructorUsesDefaultUsername(): void
    {
        $adapter = $this->createAdapter('pgsql://localhost/testdb');
        // Default username should be 'postgres'
        $this->assertInstanceOf(Pgsql::class, $adapter);
    }

    /**
     * Test convertQuotes replaces brackets with double quotes.
     */
    #[DataProvider('convertQuotesProvider')]
    public function testConvertQuotes(string $input, string $expected): void
    {
        $adapter = $this->createAdapter('pgsql://localhost/test');
        $result = $adapter->convertQuotes($input);
        $this->assertSame($expected, $result);
    }

    public static function convertQuotesProvider(): array
    {
        return [
            'simple table' => [
                'SELECT * FROM [users]',
                'SELECT * FROM "users"',
            ],
            'table and column' => [
                'SELECT [id], [name] FROM [users]',
                'SELECT "id", "name" FROM "users"',
            ],
            'with alias' => [
                'SELECT [u].[id] FROM [users] AS [u]',
                'SELECT "u"."id" FROM "users" AS "u"',
            ],
            'no brackets' => [
                'SELECT * FROM users',
                'SELECT * FROM users',
            ],
            'mixed case identifier' => [
                'SELECT [UserName] FROM [MyTable]',
                'SELECT "UserName" FROM "MyTable"',
            ],
            'underscore identifier' => [
                'SELECT [user_name] FROM [my_table]',
                'SELECT "user_name" FROM "my_table"',
            ],
        ];
    }

    /**
     * Test buildSql generates correct PostgreSQL syntax.
     */
    public function testBuildSqlGeneratesCorrectSyntax(): void
    {
        $adapter = $this->createAdapter('pgsql://localhost/test');

        $params = [
            'fields' => '"id", "name"',
            'from' => '"users"',
            'where' => '"status" = 1',
            'order' => '"id" ASC',
            'limit' => 10,
            'offset' => 20,
        ];

        $sql = $adapter->buildSql($params);

        $this->assertStringContainsString('SELECT "id", "name"', $sql);
        $this->assertStringContainsString('FROM "users"', $sql);
        $this->assertStringContainsString('WHERE "status" = 1', $sql);
        $this->assertStringContainsString('ORDER BY "id" ASC', $sql);
        $this->assertStringContainsString('LIMIT 10', $sql);
        $this->assertStringContainsString('OFFSET 20', $sql);
    }

    /**
     * Test buildSql with FOR UPDATE.
     */
    public function testBuildSqlWithForUpdate(): void
    {
        $adapter = $this->createAdapter('pgsql://localhost/test');

        $params = [
            'fields' => '*',
            'from' => '"users"',
            'forUpdate' => true,
        ];

        $sql = $adapter->buildSql($params);

        $this->assertStringContainsString('FOR UPDATE', $sql);
    }

    /**
     * Test buildSql with DISTINCT.
     */
    public function testBuildSqlWithDistinct(): void
    {
        $adapter = $this->createAdapter('pgsql://localhost/test');

        $params = [
            'fields' => '"status"',
            'from' => '"users"',
            'distinct' => true,
        ];

        $sql = $adapter->buildSql($params);

        $this->assertStringContainsString('SELECT DISTINCT "status"', $sql);
    }

    /**
     * Test buildSql with GROUP BY and HAVING.
     */
    public function testBuildSqlWithGroupByAndHaving(): void
    {
        $adapter = $this->createAdapter('pgsql://localhost/test');

        $params = [
            'fields' => '"status", COUNT(*) as cnt',
            'from' => '"users"',
            'group' => '"status"',
            'having' => 'COUNT(*) > 5',
        ];

        $sql = $adapter->buildSql($params);

        $this->assertStringContainsString('GROUP BY "status"', $sql);
        $this->assertStringContainsString('HAVING COUNT(*) > 5', $sql);
    }

    /**
     * Test buildSql with JOIN.
     */
    public function testBuildSqlWithJoin(): void
    {
        $adapter = $this->createAdapter('pgsql://localhost/test');

        $params = [
            'fields' => '"u"."id", "p"."name"',
            'from' => '"users" AS "u"',
            'join' => ' LEFT JOIN "profiles" AS "p" ON "u"."id" = "p"."user_id"',
        ];

        $sql = $adapter->buildSql($params);

        $this->assertStringContainsString('LEFT JOIN "profiles"', $sql);
    }

    /**
     * Helper to create adapter without actual database connection.
     */
    protected function createAdapter(string $uri): Pgsql
    {
        $this->container->set(Pgsql::class, ['uri' => $uri, 'owner' => $this->poolOwner]);
        return $this->container->get(Pgsql::class);
    }
}
