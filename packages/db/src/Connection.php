<?php

declare(strict_types=1);

namespace Switon\Db;

use Switon\Core\Attribute\Autowired;
use Switon\Core\MakerInterface;
use Switon\Db\Connection\Adapter\Mssql;
use Switon\Db\Connection\Adapter\Mysql;
use Switon\Db\Connection\Adapter\Pgsql;
use Switon\Db\Connection\Adapter\Sqlite;

use function is_string;
use function parse_url;

/**
 * Resolves DB adapters through a scheme-to-connector map.
 *
 * Guidance: Configure URI scheme routing via `$connectors`; avoid relying on class-name inference.
 *
 * Road-signs:
 * - URI from parameters `uri` or index `0`
 * - scheme lookup in `$connectors`
 * - adapter build through `MakerInterface`
 * - aliases (for example `postgres` -> `Pgsql`)
 * - unsupported/misconfigured scheme throws `Db\Exception`
 *
 * @see \Switon\Db\ConnectionInterface
 * @see \Switon\Core\MakerInterface
 * @see \Switon\Di\Factory
 */
class Connection
{
    #[Autowired] protected MakerInterface $maker;

    /**
     * URI scheme to adapter mapping.
     *
     * @var array<string, class-string<ConnectionInterface>|ConnectionInterface>
     */
    #[Autowired] protected array $connectors = [
        'mysql' => Mysql::class,
        'pgsql' => Pgsql::class,
        'postgres' => Pgsql::class,
        'postgresql' => Pgsql::class,
        'sqlite' => Sqlite::class,
        'mssql' => Mssql::class,
    ];

    /**
     * Resolves and creates a database adapter for the given URI.
     *
     * @param array{0?: string, uri?: string} $parameters
     */
    public function __invoke(array $parameters): ConnectionInterface
    {
        $uri = (string)($parameters[0] ?? $parameters['uri'] ?? '');
        $scheme = (parse_url($uri, PHP_URL_SCHEME) ?? '');

        if ($scheme === '') {
            Exception::raise('Invalid DB URI format: missing scheme in "{uri}"', ['uri' => $uri]);
        }

        $connector = $this->connectors[$scheme] ?? null;
        if ($connector instanceof ConnectionInterface) {
            return $connector;
        }

        if (!is_string($connector) || $connector === '') {
            Exception::raise('Unsupported DB URI scheme "{scheme}" in "{uri}"', ['scheme' => $scheme, 'uri' => $uri]);
        }
        return $this->maker->make($connector, $parameters);
    }
}
