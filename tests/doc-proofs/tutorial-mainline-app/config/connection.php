<?php
declare(strict_types=1);

use App\Db\TutorialSqliteConnection;
use Switon\Db\Connection;
use Switon\Db\Connection\Adapter\Mssql;
use Switon\Db\Connection\Adapter\Mysql;
use Switon\Db\Connection\Adapter\Pgsql;

return [
    Connection::class => [
        'connectors' => [
            'mysql' => Mysql::class,
            'pgsql' => Pgsql::class,
            'postgres' => Pgsql::class,
            'postgresql' => Pgsql::class,
            'sqlite' => TutorialSqliteConnection::class,
            'mssql' => Mssql::class,
        ],
    ],
];
