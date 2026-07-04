<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Unit;

use Switon\Db\Connection\Adapter\Mysql;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MySQL URI parsing.
 */
class MysqlUriTest extends TestCase
{
    /**
     * Test parsing of standard MySQL URI.
     */
    public function testParseStandardUri(): void
    {
        // Arrange
        $adapter = new TestableMysql('mysql://user:pass@localhost:3306/dbname');
        // Act & Assert

        $this->assertSame('mysql:host=localhost;port=3306;dbname=dbname', $adapter->getDsn());
        $this->assertSame('user', $adapter->getUsername());
        $this->assertSame('pass', $adapter->getPassword());
    }

    /**
     * Test parsing of URI with query parameters (options).
     */
    public function testParseUriWithOptions(): void
    {
        // Arrange
        $adapter = new TestableMysql('mysql://user:pass@localhost/db?charset=utf8mb4&timeout=10&readonly=1');
        // Act & Assert

        $this->assertSame('mysql:host=localhost;dbname=db', $adapter->getDsn());
        $this->assertSame('utf8mb4', $adapter->getCharset());
        $this->assertTrue($adapter->isReadonly());
    }

    /**
     * Test parsing of URI without password.
     */
    public function testParseUriWithoutPassword(): void
    {
        // Arrange
        $adapter = new TestableMysql('mysql://root@localhost/db');
        // Act & Assert

        $this->assertSame('mysql:host=localhost;dbname=db', $adapter->getDsn());
        $this->assertSame('root', $adapter->getUsername());
        $this->assertSame('', $adapter->getPassword());
    }

    /**
     * Test that invalid scheme throws exception.
     */
    public function testInvalidSchemeThrowsException(): void
    {
        // Assert
        $this->expectException(\Switon\Core\Exception\DsnFormatException::class);

        // Act
        new TestableMysql('pgsql://localhost/db');
    }
}

class TestableMysql extends Mysql
{
    public function __construct(string $uri)
    {
        // Inject URI manually since we are bypassing DI
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

    public function getCharset(): string
    {
        return $this->charset;
    }

    public function isReadonly(): bool
    {
        return $this->readonly;
    }
}
