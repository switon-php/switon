<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Switon\Core\Exception\DsnFormatException;
use Switon\Db\Connection\Adapter\Mssql;

class MssqlUriTest extends TestCase
{
    public function testParseUsesTheInstalledDriver(): void
    {
        $adapter = new TestableMssql('mssql://user:pass@127.0.0.1:1433/app');

        $drivers = PDO::getAvailableDrivers();
        $useDblib = in_array('dblib', $drivers, true) || !in_array('sqlsrv', $drivers, true);
        $expected = $useDblib
            ? 'dblib:host=127.0.0.1:1433;dbname=app'
            : 'sqlsrv:Server=127.0.0.1,1433;Database=app';

        $this->assertSame($expected, $adapter->getDsn());
        $this->assertSame('user', $adapter->getUsername());
        $this->assertSame('pass', $adapter->getPassword());
    }

    public function testInvalidSchemeThrowsException(): void
    {
        $this->expectException(DsnFormatException::class);

        new TestableMssql('mysql://localhost/db');
    }
}

class TestableMssql extends Mssql
{
    public function __construct(string $uri)
    {
        $this->uri = $uri;
        parent::__construct();
    }

    public function getDsn(): string
    {
        return $this->dsn;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }
}
