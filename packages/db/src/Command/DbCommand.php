<?php

declare(strict_types=1);

namespace Switon\Db\Command;

use Switon\Command\Attribute\Hidden;
use Switon\Command\Attribute\Tool;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Core\ConsoleInterface;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Db\Client;
use Switon\Db\ClientInterface;
use Switon\Di\NamedLookupInterface;
use Throwable;

use function fnmatch;
use function sort;
use function sprintf;

/**
 * Database inspection and metadata
 *
 * @see \Switon\Core\ConsoleInterface
 * @see \Switon\Core\Exception
 * @see \Switon\Db\ClientInterface
 * @see \Switon\Di\NamedLookupInterface
 */
class DbCommand
{
    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected ClockInterface $clock;
    /** @var NamedLookupInterface<ClientInterface> */
    #[Autowired] protected NamedLookupInterface $namedLookup;
    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected PathAliasInterface $pathAlias;

    /**
     * @param string $connection
     * @param ?string $pattern
     *
     * @return list<string>
     */
    protected function getTables(string $connection, ?string $pattern = null): array
    {
        $db = $this->namedLookup->by(ClientInterface::class, $connection);
        $tables = [];
        foreach ($db->getTables() as $table) {
            if ($pattern && !fnmatch($pattern, $table)) {
                continue;
            }
            $tables[] = $table;
        }

        sort($tables);

        return $tables;
    }

    /**
     * Build display URI from connection URI (scheme, host, port, path only; no user/pass)
     */
    protected function buildDisplayUri(string $uri): string
    {
        $parsed = parse_url($uri);
        $scheme = $parsed['scheme'] ?? '';
        $host = $parsed['host'] ?? '';
        $port = $parsed['port'] ?? null;
        $path = isset($parsed['path']) ? trim($parsed['path'], '/') : '';
        if ($scheme === '' && $host === '' && $path === '') {
            return '';
        }
        if ($scheme === 'sqlite' || $host === '') {
            return $scheme !== '' ? $scheme . ':' . ($path) : $path;
        }
        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '') . ($path !== '' ? '/' . $path : '');
    }

    /**
     * Check DB connection is reachable (script-friendly, exit 0 on success)
     *
     * @param string $connection Named connection or empty for default
     */
    #[Tool('[connection]. Exit 0 = OK, non-zero = fail.')]
    public function pingAction(string $connection = ''): int
    {
        try {
            $name = $connection !== '' ? $connection : 'default';
            $db = $this->namedLookup->by(ClientInterface::class, $name);
            $rows = $db->fetchAll('SELECT 1');
            if ($rows === [] || array_first($rows) === null) {
                return $this->console->error('Ping failed: no response');
            }
            $this->console->writeLn('OK');
            return 0;
        } catch (Throwable $e) {
            $name = $connection !== '' ? $connection : 'default';
            $msg = $e->getMessage();
            $notConfigured = str_contains($msg, 'not found') || str_contains($msg, 'not registered');
            $message = $notConfigured ? 'Connection "' . $name . '" not configured' : $msg;
            return $this->console->error($notConfigured ? $message : 'Ping failed: {message}', $notConfigured ? [] : ['message' => $msg]);
        }
    }

    /**
     * Show one DB connection status and server info
     *
     * @param string $connection Named connection or empty for default
     * @param bool $uri Include full connection URI with credentials (for agent/tooling to connect)
     * @param bool $json Output machine-readable JSON
     */
    #[Tool('[connection] [--uri]. Returns JSON: connection, status, latency_ms, uri, version.')]
    public function infoAction(string $connection = '', bool $uri = false, bool $json = true): int
    {
        try {
            $name = $connection !== '' ? $connection : 'default';
            $db = $this->namedLookup->by(ClientInterface::class, $name);

            $startedAt = $this->clock->microtime();
            $rows = $db->fetchAll('SELECT 1');
            $latency = $this->clock->elapsed($startedAt);

            if ($rows === [] || array_first($rows) === null) {
                return $this->console->error('Connection check failed: SELECT 1 returned no row');
            }

            $displayUri = '';
            $connectionUri = $db->getUri();
            if ($connectionUri !== null) {
                $displayUri = $uri ? $connectionUri : $this->buildDisplayUri($connectionUri);
            }
            $version = $db->getVersion();

            if ($json) {
                $out = [
                    'connection' => $name,
                    'status' => 'Connected',
                    'latency_ms' => round($latency * 1000, 2),
                    'uri' => $displayUri,
                    'version' => $version,
                ];
                $this->console->writeLn(json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return 0;
            }

            $headers = ['connection', 'status', 'latency', 'uri', 'version'];
            $row = [$name, 'Connected', sprintf('%.2fms', $latency * 1000), $displayUri, $version ?? ''];
            $this->console->table($headers, [$row]);

            return 0;
        } catch (Throwable $e) {
            $name = $connection !== '' ? $connection : 'default';
            if ($json) {
                $this->console->writeLn(json_encode([
                    'error' => $e->getMessage(),
                    'connection' => $name,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return 1;
            }
            return $this->console->error('Info failed: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * List connection names and table names
     *
     * @param list<string> $connections Connection names or empty for all
     * @param string $table_pattern Fnmatch pattern for table names
     * @param bool $json Output machine-readable JSON
     */
    #[Hidden, Tool('Returns JSON: tables[] → connection, table. On connection failure: tables[], errors{connection: message}; exit 1.')]
    public function listAction(array $connections = [], string $table_pattern = '', bool $json = true): int
    {
        $connNames = $connections ?: $this->namedLookup->names(ClientInterface::class);
        $rows = [];
        $errors = [];
        foreach ($connNames as $connection) {
            try {
                foreach ($this->getTables($connection, $table_pattern ?: null) as $table) {
                    $rows[] = [$connection, $table];
                }
            } catch (Throwable $e) {
                $errors[$connection] = $e->getMessage();
                if (!$json) {
                    return $this->console->error('List failed for {connection}: {message}', [
                        'connection' => $connection,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }
        if ($json) {
            $out = ['tables' => array_map(static fn ($r) => ['connection' => $r[0], 'table' => $r[1]], $rows)];
            if ($errors !== []) {
                $out['errors'] = $errors;
            }
            $this->console->writeLn(json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $errors === [] ? 0 : 1;
        }
        if ($rows === []) {
            $this->console->writeLn('No tables found.');
            return 0;
        }
        $this->console->table(['connection', 'table'], $rows);
        return 0;
    }

    /**
     * Output table metadata as JSON (attributes, primaryKey, autoIncrement)
     *
     * @param list<string> $connections Connection names or empty for all
     * @param string $table_pattern Fnmatch pattern for table names
     * @param bool $plain Compact one-line JSON when true
     */
    #[Hidden]
    public function metadataAction(array $connections = [], string $table_pattern = '', bool $plain = false): void
    {
        $result = [];
        foreach ($connections ?: $this->namedLookup->names(ClientInterface::class) as $connection) {
            $db = $this->namedLookup->by(ClientInterface::class, $connection);
            $result[$connection] = [];
            foreach ($this->getTables($connection, $table_pattern ?: null) as $table) {
                $meta = $db->getMetadata($table);
                $result[$connection][$table] = [
                    'attributes' => $meta[Client::METADATA_ATTRIBUTES] ?? [],
                    'primaryKey' => $meta[Client::METADATA_PRIMARY_KEY] ?? [],
                    'autoIncrement' => $meta[Client::METADATA_AUTO_INCREMENT_KEY] ?? null,
                ];
            }
        }
        $flags = JSON_UNESCAPED_UNICODE | ($plain ? 0 : JSON_PRETTY_PRINT);
        $this->console->write(json_encode($result, $flags));
    }
}
