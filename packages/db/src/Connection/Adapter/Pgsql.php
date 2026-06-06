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
use Throwable;

use function array_keys;
use function count;
use function explode;
use function implode;
use function is_int;
use function is_string;
use function parse_str;
use function parse_url;
use function preg_replace;
use function str_contains;
use function str_replace;
use function strtoupper;
use function trim;

/**
 * PostgreSQL adapter for the DB connection contract.
 *
 * @see \Switon\Db\AbstractConnection
 * @see \Switon\Db\Connection
 * @see \Switon\Db\Client
 * @see \Switon\Core\Exception\DsnFormatException Related failure path
 */
class Pgsql extends AbstractConnection
{
    /** @noinspection PhpTypedPropertyMightBeUninitializedInspection */
    public function __construct()
    {
        $parts = parse_url($this->uri);

        if ($parts['scheme'] !== 'pgsql' && $parts['scheme'] !== 'postgres' && $parts['scheme'] !== 'postgresql') {
            DsnFormatException::raise(
                'URI {uri} invalid, scheme {scheme} not recognized',
                ['uri' => $this->uri, 'scheme' => $parts['scheme']]
            );
        }

        $this->username = $parts['user'] ?? 'postgres';
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

            if (!Runtime::isCoroutineEnabled() && isset($query['persistent'])) {
                $this->options[PDO::ATTR_PERSISTENT] = $query['persistent'] === '1';
            }

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

        $dsn_parts = [];
        foreach ($dsn as $k => $v) {
            $dsn_parts[] = $k . '=' . $v;
        }
        $this->dsn = 'pgsql:' . implode(';', $dsn_parts);

        parent::__construct();
    }

    public function getVersion(): ?string
    {
        try {
            $rows = $this->query('SELECT version() AS v');
            $v = $rows[0]['v'] ?? null;
            return is_string($v) && $v !== '' ? $v : null;
        } catch (Throwable) {
            return null;
        }
    }

    #[ArrayShape([Client::METADATA_ATTRIBUTES => 'array',
        Client::METADATA_PRIMARY_KEY => 'array',
        Client::METADATA_AUTO_INCREMENT_KEY => 'mixed|null',
        Client::METADATA_INT_TYPE_ATTRIBUTES => 'array'])]
    public function getMetadata(string $table): array
    {
        $tableName = str_replace(['[', ']', '"'], '', $table);
        $parts = explode('.', $tableName);
        $schema = count($parts) === 2 ? $parts[0] : 'public';
        $tableName = count($parts) === 2 ? $parts[1] : $parts[0];

        // Get columns info
        $sql = /** @lang text */
            "
            SELECT 
                c.column_name,
                c.data_type,
                c.column_default,
                CASE WHEN pk.column_name IS NOT NULL THEN true ELSE false END as is_primary
            FROM information_schema.columns c
            LEFT JOIN (
                SELECT kcu.column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu 
                    ON tc.constraint_name = kcu.constraint_name
                    AND tc.table_schema = kcu.table_schema
                WHERE tc.constraint_type = 'PRIMARY KEY'
                    AND tc.table_schema = :schema
                    AND tc.table_name = :table
            ) pk ON c.column_name = pk.column_name
            WHERE c.table_schema = :schema AND c.table_name = :table
            ORDER BY c.ordinal_position
        ";

        $fields = $this->query($sql, ['schema' => $schema, 'table' => $tableName]);

        $attributes = [];
        $primaryKeys = [];
        $autoIncrementAttribute = null;
        $intTypes = [];

        foreach ($fields as $field) {
            $fieldName = $field['column_name'];
            $type = $field['data_type'];
            $default = $field['column_default'];

            $attributes[$fieldName] = $type;

            // PDO returns native PHP boolean (true/false) for PostgreSQL boolean columns.
            // Note: Old pg_* functions returned 't'/'f' strings, but PDO has returned
            // boolean since ~2010. Verified by PgsqlBooleanTypeTest.
            if ($field['is_primary'] === true) {
                $primaryKeys[] = $fieldName;
            }

            // Check for serial/sequence (auto-increment)
            if ($default !== null && str_contains($default, 'nextval(')) {
                $autoIncrementAttribute = $fieldName;
            }

            // Check for integer types
            if (str_contains(strtoupper($type), 'INT') || $type === 'serial' || $type === 'bigserial' || $type === 'smallserial') {
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
        $this->execute('TRUNCATE TABLE ' . $this->escapeIdentifier($table) . ' RESTART IDENTITY');
    }

    public function drop(string $table): void
    {
        $this->execute('DROP TABLE IF EXISTS ' . $this->escapeIdentifier($table));
    }

    public function getTables(?string $schema = null): array
    {
        $schema = $schema ?? 'public';
        $sql = /** @lang text */
            "
            SELECT table_name 
            FROM information_schema.tables 
            WHERE table_schema = :schema 
                AND table_type = 'BASE TABLE'
            ORDER BY table_name
        ";

        $tables = [];
        foreach ($this->query($sql, ['schema' => $schema]) as $row) {
            $tables[] = $row['table_name'];
        }

        return $tables;
    }

    public function tableExists(string $table): bool
    {
        $tableName = str_replace(['[', ']', '"'], '', $table);
        $parts = explode('.', $tableName);
        $schema = count($parts) === 2 ? $parts[0] : 'public';
        $tableName = count($parts) === 2 ? $parts[1] : $parts[0];

        $sql = /** @lang text */
            "
            SELECT 1 FROM information_schema.tables 
            WHERE table_schema = :schema AND table_name = :table
        ";
        $result = $this->query($sql, ['schema' => $schema, 'table' => $tableName]);

        return !empty($result);
    }

    /**
     * Convert bracket notation to PostgreSQL double-quote notation.
     */
    public function convertQuotes(string $sql): string
    {
        return str_contains($sql, '[') ? preg_replace('#\[([a-z_]\w*)\]#i', '"\\1"', $sql) : $sql;
    }

    /**
     * Atomic upsert using PostgreSQL's INSERT ... ON CONFLICT DO UPDATE.
     *
     * @param string $table Table name with bracket notation
     * @param array<string, mixed> $insertFieldValues Values for INSERT
     * @param array<string|int, mixed> $updateFieldValues Values for UPDATE
     * @param string $primaryKey Primary key field name
     *
     * @return int Number of affected rows
     */
    public function upsert(string $table, array $insertFieldValues, array $updateFieldValues, string $primaryKey): int
    {
        $table = $this->convertQuotes($table);
        $fields = array_keys($insertFieldValues);

        $bind = $insertFieldValues;
        $updates = [];
        foreach ($updateFieldValues as $k => $v) {
            $field = is_string($k) ? $k : $v;
            if ($primaryKey === $field) {
                continue;
            }

            if (is_int($k)) {
                $updates[] = "\"$field\"=:{$field}_dku";
                $bind["{$field}_dku"] = $insertFieldValues[$field];
            } elseif ($this->isUpsertExpression($v)) {
                $updates[] = $this->convertQuotes($this->getUpsertExpression($v));
            } else {
                $updates[] = "\"$k\"=:{$k}_dku";
                $bind["{$k}_dku"] = $v;
            }
        }

        $insertFieldsSql = '"' . implode('","', $fields) . '"';
        $insertValuesSql = ':' . implode(',:', $fields);
        $updateFieldsSql = implode(',', $updates);

        $sql
            = /** @lang text */
            "INSERT INTO $table($insertFieldsSql) VALUES($insertValuesSql)"
            . " ON CONFLICT(\"$primaryKey\") DO UPDATE SET $updateFieldsSql";

        return $this->execute($sql, $bind);
    }

    /**
     * Allocates an inclusive ID range by advancing the table sequence.
     *
     * Uses read-modify-verify logic and fails on detected concurrent writes.
     *
     * @return array{int, int} [startId, endId]
     *
     * @throws \Switon\Db\Exception
     */
    public function allocateIds(string $table, string $primaryKey, int $count): array
    {
        $fullTable = $this->escapeIdentifier($table);

        // Step 1: Get sequence name from column default
        $seqResult = $this->query(/** @lang text */ "
            SELECT pg_get_serial_sequence(:table, :column) as seq_name
        ", ['table' => $table, 'column' => $primaryKey]);

        $seqName = $seqResult[0]['seq_name'] ?? null;
        if (!$seqName) {
            Exception::raise('No sequence found for column "{column}" in table "{table}"', ['table' => $table, 'column' => $primaryKey]);
        }

        // Step 2: Query current state
        $result = $this->query(/** @lang text */ "
            SELECT 
                (SELECT COALESCE(MAX($primaryKey), 0) FROM $fullTable) as max_id,
                (SELECT last_value FROM $seqName) as seq_value
        ");
        $maxIdBefore = (int)($result[0]['max_id'] ?? 0);
        $currentSeq = (int)($result[0]['seq_value'] ?? 0);

        // Step 3: Calculate range and update sequence
        $startId = max($maxIdBefore + 1, $currentSeq + 1);
        $endId = $startId + $count - 1;

        $this->execute(/** @lang text */ "SELECT setval(:seq, :val)", ['seq' => $seqName, 'val' => $endId]);

        // Step 4: Verify no concurrent operations
        $verifyResult = $this->query(/** @lang text */ "
            SELECT 
                (SELECT COALESCE(MAX($primaryKey), 0) FROM $fullTable) as max_id,
                (SELECT last_value FROM $seqName) as seq_value
        ");
        $maxIdAfterFinal = (int)($verifyResult[0]['max_id'] ?? 0);
        $seqAfterFinal = (int)($verifyResult[0]['seq_value'] ?? 0);

        if ($maxIdAfterFinal !== $maxIdBefore) {
            Exception::raise('Concurrent insert detected on table "{table}" during ID allocation', ['table' => $table]);
        }

        if ($seqAfterFinal !== $endId) {
            Exception::raise('Concurrent ID allocation detected on table "{table}"', ['table' => $table]);
        }

        return [$startId, $endId];
    }
}
