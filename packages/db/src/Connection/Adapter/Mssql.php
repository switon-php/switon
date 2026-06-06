<?php

declare(strict_types=1);

namespace Switon\Db\Connection\Adapter;

use JetBrains\PhpStorm\ArrayShape;
use PDO;
use Switon\Core\Exception\DsnFormatException;
use Switon\Core\Exception\PreconditionException;
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
use function parse_url;
use function str_contains;
use function str_replace;
use function trim;

/**
 * Microsoft SQL Server adapter for the DB connection contract.
 *
 * @see \Switon\Db\AbstractConnection
 * @see \Switon\Db\Connection
 * @see \Switon\Db\Client
 * @see \Switon\Core\Exception\DsnFormatException Related failure path
 */
class Mssql extends AbstractConnection
{
    /** @noinspection PhpTypedPropertyMightBeUninitializedInspection */
    public function __construct()
    {
        $parts = parse_url($this->uri);

        if ($parts['scheme'] !== 'mssql') {
            DsnFormatException::raise(
                'URI {uri} invalid, scheme {scheme} not recognized',
                ['uri' => $this->uri, 'scheme' => $parts['scheme']]
            );
        }

        $this->username = $parts['user'] ?? '';
        $this->password = $parts['pass'] ?? '';

        $dsn = [];
        $use_dblib = DIRECTORY_SEPARATOR === '/';

        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? '1433';

        $dsn[$use_dblib ? 'host' : 'Server'] = $host . ($use_dblib ? ':' : ',') . $port;

        if (isset($parts['path'])) {
            $path = trim($parts['path'], '/');
            if ($path !== '') {
                $dsn[$use_dblib ? 'dbname' : 'Database'] = $path;
            }
        }

        $this->options[PDO::ATTR_STRINGIFY_FETCHES] = true;

        $dsn_parts = [];
        foreach ($dsn as $k => $v) {
            $dsn_parts[] = $k . '=' . $v;
        }

        $this->dsn = ($use_dblib ? 'dblib:' : 'sqlsrv:') . implode(';', $dsn_parts);

        parent::__construct();
    }

    public function getVersion(): ?string
    {
        try {
            $rows = $this->query('SELECT @@VERSION AS v');
            $v = $rows[0]['v'] ?? null;
            return is_string($v) && $v !== '' ? trim($v) : null;
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
        $parts = explode('.', str_replace(['[', ']'], '', $table));

        if (count($parts) === 1) {
            $fields = $this->query('exec sp_pkeys @table_name = :table', ['table' => $parts[0]]);
        } else {
            $fields = $this->query('exec sp_pkeys @table_name = :table, @table_owner = :owner', ['table' => $parts[1], 'owner' => $parts[0]]);
        }

        $primaryKeys = [];
        foreach ($fields as $field) {
            $primaryKeys[] = $field['COLUMN_NAME'];
        }

        if (count($parts) === 1) {
            $fields = $this->query('exec sp_columns @table_name = :table', ['table' => $parts[0]]);
        } else {
            $fields = $this->query('exec sp_columns @table_name = :table, @table_owner = :owner', ['table' => $parts[1], 'owner' => $parts[0]]);
        }

        $attributes = [];
        $autoIncrementAttribute = null;
        $intTypes = [];

        foreach ($fields as $field) {
            $fieldName = $field['COLUMN_NAME'];
            $type = $field['TYPE_NAME'];

            $attributes[$fieldName] = $type;

            if ($type === 'int identity') {
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
        $this->execute('TRUNCATE TABLE ' . $this->escapeIdentifier($table));
    }

    public function drop(string $table): void
    {
        $this->execute('DROP TABLE IF EXISTS ' . $this->escapeIdentifier($table));
    }

    public function getTables(?string $schema = null): array
    {
        if ($schema) {
            $sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA = :schema ORDER BY TABLE_NAME";
            $rows = $this->query($sql, ['schema' => $schema]);
        } else {
            $sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME";
            $rows = $this->query($sql);
        }

        $tables = [];
        foreach ($rows as $row) {
            $tables[] = $row['TABLE_NAME'];
        }
        return $tables;
    }

    public function tableExists(string $table): bool
    {
        $parts = explode('.', str_replace(['[', ']'], '', $table));

        if (count($parts) === 2) {
            $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = :table AND TABLE_SCHEMA = :schema";
            $result = $this->query($sql, ['table' => $parts[1], 'schema' => $parts[0]]);
        } else {
            $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = :table";
            $result = $this->query($sql, ['table' => $parts[0]]);
        }

        return !empty($result);
    }

    public function buildSql(array $params): string
    {
        $sql = '';

        if (isset($params['fields'])) {
            $sql .= 'SELECT ';
            if (isset($params['limit']) && !isset($params['offset'])) {
                $sql .= 'TOP ' . $params['limit'] . ' ';
            }
            if (isset($params['distinct'])) {
                $sql .= 'DISTINCT ';
            }

            $sql .= $params['fields'];
            if (isset($params['limit'], $params['offset'])) {
                if (!isset($params['order'])) {
                    PreconditionException::raise('SQL Server requires ORDER BY clause when using OFFSET pagination');
                }

                $sql .= ', ROW_NUMBER() OVER (ORDER BY ' . $params['order'] . ') AS _row_number_';
            }
        }

        if (isset($params['from'])) {
            $sql .= ' FROM ' . $params['from'];
        }

        if (isset($params['join'])) {
            $sql .= $params['join'];
        }

        if (isset($params['where'])) {
            $sql .= ' WHERE ' . $params['where'];
        }

        if (isset($params['group'])) {
            $sql .= ' GROUP BY ' . $params['group'];
        }

        if (isset($params['having'])) {
            $sql .= ' HAVING ' . $params['having'];
        }

        if (isset($params['limit'], $params['offset'])) {
            $offset = $params['offset'];
            $limit = $params['limit'];
            $sql = 'SELECT' . " t.* FROM ($sql) as t WHERE t._row_number_ BETWEEN $offset + 1 AND $offset + $limit";
        }

        if (isset($params['order']) && !isset($params['offset'])) {
            $sql .= ' ORDER BY ' . $params['order'];
        }

        if (isset($params['forUpdate'])) {
            $sql .= ' FOR UPDATE';
        }

        return $sql;
    }

    public function convertQuotes(string $sql): string
    {
        return $sql;
    }

    /**
     * Atomic upsert using SQL Server's MERGE statement.
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
        $fields = array_keys($insertFieldValues);

        $bind = $insertFieldValues;
        $updates = [];
        foreach ($updateFieldValues as $k => $v) {
            $field = is_string($k) ? $k : $v;
            if ($primaryKey === $field) {
                continue;
            }

            if (is_int($k)) {
                $updates[] = "target.[$field]=:{$field}_dku";
                $bind["{$field}_dku"] = $insertFieldValues[$field];
            } elseif ($this->isUpsertExpression($v)) {
                $updates[] = preg_replace('/\[([a-z_]\w*)\]/i', 'target.[$1]', $this->getUpsertExpression($v));
            } else {
                $updates[] = "target.[$k]=:{$k}_dku";
                $bind["{$k}_dku"] = $v;
            }
        }

        $insertFieldsSql = '[' . implode('],[', $fields) . ']';
        $insertValuesSql = ':' . implode(',:', $fields);
        $updateFieldsSql = implode(',', $updates);

        // Build source values for MERGE
        $sourceValues = [];
        foreach ($fields as $field) {
            $sourceValues[] = ":$field AS [$field]";
        }
        $sourceSql = implode(',', $sourceValues);

        $sql
            = /** @lang text */
            "MERGE INTO $table AS target "
            . "USING (SELECT $sourceSql) AS source "
            . "ON target.[$primaryKey] = source.[$primaryKey] "
            . "WHEN MATCHED THEN UPDATE SET $updateFieldsSql "
            . "WHEN NOT MATCHED THEN INSERT ($insertFieldsSql) VALUES ($insertValuesSql);";

        return $this->execute($sql, $bind);
    }

    /**
     * Allocates an inclusive ID range by reseeding SQL Server IDENTITY.
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

        $result = $this->query(/**@lang text */ "SELECT 
                (SELECT MAX($primaryKey) FROM $fullTable) as max_id,
                (SELECT IDENT_CURRENT(:table)) as identity_value",
            ['table' => $table],
        );
        $maxIdBefore = (int)($result[0]['max_id'] ?? 0);
        $currentIdentity = (int)($result[0]['identity_value'] ?? 0);

        $startId = max($maxIdBefore + 1, $currentIdentity + 1);
        $endId = $startId + $count - 1;
        $reseedValue = $endId;

        $this->execute(/**@lang text */ "DBCC CHECKIDENT ($fullTable, RESEED, $reseedValue)");

        $verifyResult = $this->query(/**@lang text */ "SELECT 
                (SELECT MAX($primaryKey) FROM $fullTable) as max_id,
                (SELECT IDENT_CURRENT(:table)) as identity_value",
            ['table' => $table],
        );
        $maxIdAfterFinal = (int)($verifyResult[0]['max_id'] ?? 0);
        $identityAfterFinal = (int)($verifyResult[0]['identity_value'] ?? 0);

        if ($maxIdAfterFinal !== $maxIdBefore) {
            Exception::raise('Concurrent insert detected on table "{table}" during ID allocation', ['table' => $table]);
        }

        if ($identityAfterFinal !== $reseedValue) {
            Exception::raise('Concurrent ID allocation detected on table "{table}"', ['table' => $table]);
        }

        return [$startId, $endId];
    }
}
