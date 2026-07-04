<?php

declare(strict_types=1);

namespace Switon\Db\Connection\Adapter;

use JetBrains\PhpStorm\ArrayShape;
use PDO;
use Switon\Core\Exception\DsnFormatException;
use Switon\Core\Runtime;
use Switon\Db\AbstractConnection;
use Switon\Db\Client;
use Switon\Db\Exception;

use function array_keys;
use function count;
use function explode;
use function implode;
use function is_int;
use function is_string;
use function parse_str;
use function parse_url;
use function str_contains;
use function str_replace;
use function trim;

/**
 * MySQL adapter for the DB connection contract.
 *
 * @see \Switon\Db\AbstractConnection
 * @see \Switon\Db\Connection
 * @see \Switon\Db\Client
 * @see \Switon\Core\Exception\DsnFormatException Related failure path
 */
class Mysql extends AbstractConnection
{
    protected string $charset = 'UTF8';

    /** @noinspection PhpTypedPropertyMightBeUninitializedInspection */
    public function __construct()
    {
        $parts = parse_url($this->uri);

        if ($parts['scheme'] !== 'mysql') {
            DsnFormatException::raise(
                'URI {uri} invalid, scheme {scheme} not recognized',
                ['uri' => $this->uri, 'scheme' => $parts['scheme']]
            );
        }

        $this->username = $parts['user'] ?? 'root';
        $this->password = $parts['pass'] ?? '';

        $dsn = [];

        if (isset($parts['host'])) {
            $dsn['host'] = $parts['host'];
        }

        if (isset($parts['port'])) {
            $dsn['port'] = $parts['port'];
        }

        if (isset($parts['path'])) {
            $db = trim($parts['path'], '/');
            if ($db !== '') {
                $dsn['dbname'] = $db;
            }
        }

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);

            if (isset($query['charset'])) {
                $this->charset = $query['charset'];
            }

            if (!Runtime::isCoroutineEnabled() && isset($query['persistent'])) {
                $this->options[PDO::ATTR_PERSISTENT] = $query['persistent'] === '1';
            }

            $this->options[PDO::ATTR_TIMEOUT] = isset($query['timeout']) ? (int)$query['timeout'] : 3;

            if (isset($query['user'])) {
                $this->username = $query['user'];
            }

            if (isset($query['password'])) {
                $this->password = $query['password'];
            }

            if (isset($query['db'])) {
                $dsn['dbname'] = $query['db'];
            }

            if (isset($query['readonly']) && $query['readonly'] !== '0') {
                $this->readonly = true;
            }

            if (isset($query['emulate_prepares']) && $query['emulate_prepares'] !== '0') {
                $this->emulate_prepares = true;
            }
        }

        // PHP 8.5 deprecates PDO::MYSQL_ATTR_*; Pdo\Mysql::ATTR_* exists from PHP 8.4 (pdo_mysql).
        $initCommandAttr = class_exists(\Pdo\Mysql::class, false)
            ? \Pdo\Mysql::ATTR_INIT_COMMAND
            : PDO::MYSQL_ATTR_INIT_COMMAND;
        $this->options[$initCommandAttr] = "SET NAMES '$this->charset'";

        $dsn_parts = [];
        foreach ($dsn as $k => $v) {
            $dsn_parts[] = $k . '=' . $v;
        }
        $this->dsn = 'mysql:' . implode(';', $dsn_parts);

        parent::__construct();
    }

    #[ArrayShape([Client::METADATA_ATTRIBUTES => 'array',
        Client::METADATA_PRIMARY_KEY => 'array',
        Client::METADATA_AUTO_INCREMENT_KEY => 'mixed|null',
        Client::METADATA_INT_TYPE_ATTRIBUTES => 'array'])]
    public function getMetadata(string $table): array
    {
        $fields = $this->query('DESCRIBE ' . $this->escapeIdentifier($table), [], PDO::FETCH_NUM);

        $attributes = [];
        $primaryKeys = [];
        $autoIncrementAttribute = null;
        $intTypes = [];

        foreach ($fields as $field) {
            [$fieldName, $type] = $field;

            $attributes[$fieldName] = $type;

            if ($field[3] === 'PRI') {
                $primaryKeys[] = $fieldName;
            }

            if ($field[5] === 'auto_increment') {
                $autoIncrementAttribute = $fieldName;
            }

            if (str_contains($type, 'int')) {
                $intTypes[] = $fieldName;
            }
        }

        return [
            Client::METADATA_ATTRIBUTES => $attributes,
            Client::METADATA_PRIMARY_KEY => $primaryKeys,
            Client::METADATA_AUTO_INCREMENT_KEY => $autoIncrementAttribute,
            Client::METADATA_INT_TYPE_ATTRIBUTES => $intTypes,
        ];
    }

    public function truncate(string $table): void
    {
        $this->execute('TRUNCATE' . ' TABLE ' . $this->escapeIdentifier($table));
    }

    public function drop(string $table): void
    {
        $this->execute('DROP' . ' TABLE IF EXISTS ' . $this->escapeIdentifier($table));
    }

    public function getTables(?string $schema = null): array
    {
        if ($schema) {
            // Use bracket notation and rely on convertQuotes() (called by AbstractConnection::query)
            // to convert identifiers for the current adapter.
            $sql = 'SHOW FULL TABLES FROM ' . $this->escapeIdentifier($schema) . ' WHERE Table_Type != "VIEW"';
        } else {
            $sql = 'SHOW FULL TABLES WHERE Table_Type != "VIEW"';
        }

        $tables = [];
        foreach ($this->query($sql, [], PDO::FETCH_NUM) as $row) {
            $tables[] = $row[0];
        }

        return $tables;
    }

    public function tableExists(string $table): bool
    {
        $parts = explode('.', str_replace(['[', ']', '`'], '', $table));

        if (count($parts) === 2) {
            $sql
                = /**@lang text */
                'SELECT IF(COUNT(*) > 0, 1, 0) FROM `INFORMATION_SCHEMA`.`TABLES`'
                . ' WHERE `TABLE_NAME` = :table AND `TABLE_SCHEMA` = :schema';
            $bind = ['table' => $parts[1], 'schema' => $parts[0]];
        } else {
            $sql
                = /** @lang text */
                'SELECT IF(COUNT(*) > 0, 1, 0) FROM `INFORMATION_SCHEMA`.`TABLES`'
                . ' WHERE `TABLE_NAME` = :table AND `TABLE_SCHEMA` = DATABASE()';
            $bind = ['table' => $parts[0]];
        }

        $r = $this->query($sql, $bind, PDO::FETCH_NUM);

        return !empty($r) && $r[0][0];
    }

    public function convertQuotes(string $sql): string
    {
        return str_contains($sql, '[') ? preg_replace(/**@lang text */ '#\[([a-z_]\w*)\]#i', '`\\1`', $sql) : $sql;
    }

    /**
     * Atomic upsert using MySQL's INSERT ... ON DUPLICATE KEY UPDATE.
     *
     * @param string $table Table name with bracket notation
     * @param array<string, mixed> $insertFieldValues Values for INSERT
     * @param array<string|int, mixed> $updateFieldValues Values for UPDATE
     * @param string $primaryKey Primary key field name
     *
     * @return int Number of affected rows (1 for insert, 2 for update)
     */
    public function upsert(string $table, array $insertFieldValues, array $updateFieldValues, string $primaryKey): int
    {
        $table = $this->convertQuotes($table);
        $fields = array_keys($insertFieldValues);

        [$updates, $bind] = $this->prepareUpsertAssignments(
            $updateFieldValues,
            $insertFieldValues,
            $primaryKey,
            '_dku',
            $insertFieldValues,
            fn (string $field): string => "`$field`",
        );

        $insertFieldsSql = '`' . implode('`,`', $fields) . '`';
        $insertValuesSql = ':' . implode(',:', $fields);
        $updateFieldsSql = implode(',', $updates);

        $sql
            = /** @lang text */
            "INSERT INTO $table($insertFieldsSql)"
            . " VALUES($insertValuesSql) ON DUPLICATE KEY UPDATE $updateFieldsSql";

        return $this->execute($sql, $bind);
    }

    /**
     * Allocates an inclusive ID range by advancing AUTO_INCREMENT.
     *
     * Uses read-modify-verify logic and fails on detected concurrent inserts.
     *
     * @return array{int, int} [startId, endId]
     *
     * @throws \Switon\Db\Exception
     */
    public function allocateIds(string $table, string $primaryKey, int $count): array
    {
        $fullTable = $this->escapeIdentifier($table);

        // Step 1: Query current state
        $result = $this->query(/**@lang text */ "SELECT 
                (SELECT MAX($primaryKey) FROM $fullTable) as max_id,
                (SELECT Auto_increment FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table) as auto_increment",
            ['table' => $table],
        );
        $maxIdBefore = (int)($result[0]['max_id'] ?? 0);
        $currentAI = (int)($result[0]['auto_increment'] ?? 0);
        if ($currentAI === 0) {
            Exception::raise('Table "{table}" not found in database', ['table' => $table]);
        }

        // Step 2 & 3: Calculate range and update AUTO_INCREMENT
        $startId = max($maxIdBefore + 1, $currentAI);
        $newAI = $startId + $count;
        $this->execute(/**@lang text */ "ALTER TABLE $fullTable AUTO_INCREMENT = $newAI");

        // Step 4: Verify no concurrent inserts
        // Note: We only verify MAX(id) because INFORMATION_SCHEMA.TABLES caches
        // AUTO_INCREMENT value in MySQL 8.0+ and may not reflect the updated value immediately.
        // The ALTER TABLE statement is atomic, so if MAX(id) hasn't changed, the allocation succeeded.
        $verifyResult = $this->query(
            /**@lang text */
            "SELECT MAX($primaryKey) as max_id FROM $fullTable"
        );
        $maxIdAfterFinal = (int)($verifyResult[0]['max_id'] ?? 0);

        if ($maxIdAfterFinal !== $maxIdBefore) {
            Exception::raise('Concurrent insert detected on table "{table}" during ID allocation', ['table' => $table]);
        }

        return [$startId, $startId + $count - 1];
    }
}
