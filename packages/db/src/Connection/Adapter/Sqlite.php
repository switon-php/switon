<?php

declare(strict_types=1);

namespace Switon\Db\Connection\Adapter;

use JetBrains\PhpStorm\ArrayShape;
use PDO;
use Switon\Core\Attribute\Autowired;
use Switon\Core\PathAliasInterface;
use Switon\Db\AbstractConnection;
use Switon\Db\Client;
use Switon\Db\Exception;
use Throwable;

use function array_keys;
use function explode;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function parse_url;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strtoupper;

/**
 * SQLite adapter for the DB connection contract.
 *
 * @see \Switon\Db\AbstractConnection
 * @see \Switon\Db\Connection
 * @see \Switon\Db\Client
 * @see \Switon\Core\PathAliasInterface @public resolve boundary
 */
class Sqlite extends AbstractConnection
{
    #[Autowired] protected PathAliasInterface $pathAlias;

    /** @noinspection PhpTypedPropertyMightBeUninitializedInspection */
    public function __construct()
    {
        // SQLite doesn't use username/password, but AbstractConnection requires them
        $this->username = '';
        $this->password = '';

        $this->dsn = $this->resolveDsn($this->uri);
        parent::__construct();
    }

    /**
     * Builds a PDO DSN from a Switon DB URI or a bare SQLite path.
     *
     * Accepts documented forms such as <code>sqlite::memory:</code> and bare <code>:memory:</code>.
     * A full URI must not be passed through as the database filename (that would create <code>sqlite::memory:</code> on disk).
     */
    protected function resolveDsn(string $uri): string
    {
        if (str_starts_with($uri, 'sqlite:///')) {
            return 'sqlite:' . $this->pathAlias->resolve(substr($uri, 9));
        }

        $parts = parse_url($uri);
        if (is_array($parts) && ($parts['scheme'] ?? '') === 'sqlite') {
            if (($parts['path'] ?? '') === ':memory:' || ($parts['host'] ?? '') === ':memory') {
                return 'sqlite::memory:';
            }

            $path = $parts['path'] ?? '';
            if ($path !== '') {
                return 'sqlite:' . $this->pathAlias->resolve($path);
            }
        }

        $file = $this->pathAlias->resolve($uri);
        if ($file === ':memory:') {
            return 'sqlite::memory:';
        }

        return 'sqlite:' . $file;
    }

    public function getVersion(): ?string
    {
        try {
            $rows = $this->query('SELECT sqlite_version() AS v');
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
        $fields = $this->query('PRAGMA table_info(' . $this->escapeIdentifier($table) . ')');

        $attributes = [];
        $primaryKeys = [];
        $autoIncrementAttribute = null;
        $intTypes = [];

        foreach ($fields as $field) {
            $fieldName = $field['name'];
            $type = $field['type'];

            $attributes[$fieldName] = $type;

            // pk field can be int or string depending on PDO driver version
            if ($field['pk'] == 1) {
                $primaryKeys[] = $fieldName;
            }

            if ($field['pk'] == 1 && $type === 'INTEGER') {
                $autoIncrementAttribute = $fieldName;
            }

            if (str_contains(strtoupper($type), 'INT')) {
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
        $this->execute('DELETE' . ' FROM ' . $this->escapeIdentifier($table));
        $this->execute('DELETE' . ' FROM sqlite_sequence WHERE name=:name', ['name' => $table]);
    }

    public function drop(string $table): void
    {
        $this->execute('DROP' . ' TABLE IF EXISTS ' . $this->escapeIdentifier($table));
    }

    public function getTables(?string $schema = null): array
    {
        $sql = 'SELECT' . " tbl_name FROM sqlite_master WHERE type = 'table' ORDER BY tbl_name";
        $tables = [];
        foreach ($this->query($sql) as $row) {
            $tables[] = $row['tbl_name'];
        }

        return $tables;
    }

    public function tableExists(string $table): bool
    {
        $parts = explode('.', str_replace(['[', ']', '`'], '', $table));
        // SQLite doesn't support schemas; if schema.table format is given, use the table name part
        $tableName = count($parts) === 2 ? $parts[1] : $parts[0];

        $sql
            = /** @lang text */
            'SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END FROM sqlite_master'
            . " WHERE type='table' AND tbl_name=:table";

        $r = $this->query($sql, ['table' => $tableName], PDO::FETCH_NUM);

        return !empty($r) && $r[0][0];
    }

    /** Converts bracket notation <code>[field]</code> to SQLite quote style. */
    public function convertQuotes(string $sql): string
    {
        return str_contains($sql, '[') ? preg_replace(/**@lang text */ '#\[([a-z_]\w*)\]#i', '`\\1`', $sql) : $sql;
    }

    /**
     * Atomic upsert using SQLite's INSERT ... ON CONFLICT DO UPDATE.
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
                $updates[] = "`$field`=:{$field}_dku";
                $bind["{$field}_dku"] = $insertFieldValues[$field];
            } elseif ($this->isUpsertExpression($v)) {
                $updates[] = $this->convertQuotes($this->getUpsertExpression($v));
            } else {
                $updates[] = "`$k`=:{$k}_dku";
                $bind["{$k}_dku"] = $v;
            }
        }

        $insertFieldsSql = '`' . implode('`,`', $fields) . '`';
        $insertValuesSql = ':' . implode(',:', $fields);
        $updateFieldsSql = implode(',', $updates);

        $sql
            = /** @lang text */
            "INSERT INTO $table($insertFieldsSql) VALUES($insertValuesSql)"
            . " ON CONFLICT(`$primaryKey`) DO UPDATE SET $updateFieldsSql";

        return $this->execute($sql, $bind);
    }

    /**
     * Allocates an inclusive ID range via <code>sqlite_sequence</code>.
     *
     * Must run outside transactions and fails on detected concurrent writes.
     *
     * @return array{int, int} [startId, endId]
     *
     * @throws \Switon\Db\Exception
     */
    public function allocateIds(string $table, string $primaryKey, int $count): array
    {
        $fullTable = $this->escapeIdentifier($table);

        if ($this->in_transaction) {
            Exception::raise('allocateIds() cannot be called within a transaction, call it before begin()');
        }

        $result = $this->query(/**@lang text */ "SELECT 
                (SELECT MAX($primaryKey) FROM $fullTable) as max_id,
                (SELECT seq FROM sqlite_sequence WHERE name = :table) as seq",
            ['table' => $table],
        );
        $maxIdBefore = (int)($result[0]['max_id'] ?? 0);
        $currentSeq = (int)($result[0]['seq'] ?? 0);

        $startId = max($maxIdBefore + 1, $currentSeq + 1);
        $newSeq = $startId + $count - 1;

        if ($currentSeq === 0) {
            $this->execute(/**@lang text */ 'INSERT INTO sqlite_sequence (name, seq) VALUES (:name, :seq)', ['name' => $table, 'seq' => $newSeq]);
        } else {
            $this->execute(/**@lang text */ 'UPDATE sqlite_sequence SET seq = :seq WHERE name = :name', ['seq' => $newSeq, 'name' => $table]);
        }

        $verifyResult = $this->query(/**@lang text */ "SELECT 
                (SELECT MAX($primaryKey) FROM $fullTable) as max_id,
                (SELECT seq FROM sqlite_sequence WHERE name = :table) as seq",
            ['table' => $table],
        );
        $maxIdAfterFinal = (int)($verifyResult[0]['max_id'] ?? 0);
        $seqAfterFinal = (int)($verifyResult[0]['seq'] ?? 0);

        if ($maxIdAfterFinal !== $maxIdBefore) {
            Exception::raise('Concurrent insert detected on table "{table}" during ID allocation', ['table' => $table]);
        }

        if ($seqAfterFinal !== $newSeq) {
            Exception::raise('Concurrent ID allocation detected on table "{table}"', ['table' => $table]);
        }

        return [$startId, $startId + $count - 1];
    }
}
