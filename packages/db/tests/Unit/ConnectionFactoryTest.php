<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Switon\Core\MakerInterface;
use Switon\Db\Connection;
use Switon\Db\ConnectionInterface;
use Switon\Db\Exception;
use Switon\Db\Tests\TestCase;

/**
 * Unit tests for Connection factory.
 */
#[AllowMockObjectsWithoutExpectations]
class ConnectionFactoryTest extends TestCase
{
    protected MakerInterface|MockObject $maker;
    protected Connection $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->maker = $this->createMock(MakerInterface::class);
        $this->factory = new Connection();
        $this->container->set(MakerInterface::class, $this->maker);
        $this->injector->inject($this->factory);
    }

    /**
     * Test that __invoke creates Mysql adapter from mysql scheme.
     */
    public function testInvokeCreatesMysqlAdapter(): void
    {
        // Arrange
        $parameters = ['uri' => 'mysql://user:pass@localhost/db'];
        $expectedAdapter = 'Switon\Db\Connection\Adapter\Mysql';
        $mockConnection = $this->createMock(ConnectionInterface::class);

        $this->maker->expects($this->once())
            ->method('make')
            ->with($expectedAdapter, $parameters)
            ->willReturn($mockConnection);

        // Act
        $result = ($this->factory)($parameters);

        // Assert
        $this->assertSame($mockConnection, $result);
    }

    /**
     * Test that __invoke creates Pgsql adapter from pgsql scheme.
     */
    public function testInvokeCreatesPgsqlAdapter(): void
    {
        // Arrange
        $parameters = ['uri' => 'pgsql://user:pass@localhost/db'];
        $expectedAdapter = 'Switon\Db\Connection\Adapter\Pgsql';
        $mockConnection = $this->createMock(ConnectionInterface::class);

        $this->maker->expects($this->once())
            ->method('make')
            ->with($expectedAdapter, $parameters)
            ->willReturn($mockConnection);

        // Act
        $result = ($this->factory)($parameters);

        // Assert
        $this->assertSame($mockConnection, $result);
    }

    /**
     * Test that __invoke creates Pgsql adapter from postgres scheme alias.
     */
    public function testInvokeCreatesPgsqlAdapterFromPostgresAlias(): void
    {
        // Arrange
        $parameters = ['uri' => 'postgres://user:pass@localhost/db'];
        $expectedAdapter = 'Switon\Db\Connection\Adapter\Pgsql';
        $mockConnection = $this->createMock(ConnectionInterface::class);

        $this->maker->expects($this->once())
            ->method('make')
            ->with($expectedAdapter, $parameters)
            ->willReturn($mockConnection);

        // Act
        $result = ($this->factory)($parameters);

        // Assert
        $this->assertSame($mockConnection, $result);
    }

    /**
     * Test that __invoke creates Pgsql adapter from postgresql scheme alias.
     */
    public function testInvokeCreatesPgsqlAdapterFromPostgresqlAlias(): void
    {
        // Arrange
        $parameters = ['uri' => 'postgresql://user:pass@localhost/db'];
        $expectedAdapter = 'Switon\Db\Connection\Adapter\Pgsql';
        $mockConnection = $this->createMock(ConnectionInterface::class);

        $this->maker->expects($this->once())
            ->method('make')
            ->with($expectedAdapter, $parameters)
            ->willReturn($mockConnection);

        // Act
        $result = ($this->factory)($parameters);

        // Assert
        $this->assertSame($mockConnection, $result);
    }

    /**
     * Test that __invoke creates Sqlite adapter from sqlite scheme.
     */
    public function testInvokeCreatesSqliteAdapter(): void
    {
        // Arrange
        $parameters = ['uri' => 'sqlite:/path/to/db.sqlite'];
        $expectedAdapter = 'Switon\Db\Connection\Adapter\Sqlite';
        $mockConnection = $this->createMock(ConnectionInterface::class);

        $this->maker->expects($this->once())
            ->method('make')
            ->with($expectedAdapter, $parameters)
            ->willReturn($mockConnection);

        // Act
        $result = ($this->factory)($parameters);

        // Assert
        $this->assertSame($mockConnection, $result);
    }

    /**
     * Test that __invoke uses indexed parameter '0' if 'uri' key is missing.
     */
    public function testInvokeUsesIndexedParameterForUri(): void
    {
        // Arrange
        $parameters = ['mysql://user:pass@localhost/db'];
        $expectedAdapter = 'Switon\Db\Connection\Adapter\Mysql';
        $mockConnection = $this->createMock(ConnectionInterface::class);

        $this->maker->expects($this->once())
            ->method('make')
            ->with($expectedAdapter, $parameters)
            ->willReturn($mockConnection);

        // Act
        $result = ($this->factory)($parameters);

        // Assert
        $this->assertSame($mockConnection, $result);
    }

    public function testInvokePrefersIndexedUriOverNamedUri(): void
    {
        $parameters = [
            'pgsql://user:pass@localhost/db',
            'uri' => 'mysql://user:pass@localhost/db',
        ];
        $expectedAdapter = 'Switon\Db\Connection\Adapter\Pgsql';
        $mockConnection = $this->createMock(ConnectionInterface::class);

        $this->maker->expects($this->once())
            ->method('make')
            ->with($expectedAdapter, $parameters)
            ->willReturn($mockConnection);

        $result = ($this->factory)($parameters);

        $this->assertSame($mockConnection, $result);
    }

    /**
     * Test that __invoke can use configured connector instance from scheme map.
     */
    public function testInvokeUsesConfiguredConnectorInstance(): void
    {
        // Arrange
        $parameters = ['uri' => 'custom://host/db'];
        $mockConnection = $this->createMock(ConnectionInterface::class);
        $factory = new Connection();
        $this->injector->inject($factory, [
            'connectors' => ['custom' => $mockConnection],
        ]);

        $this->maker->expects($this->never())->method('make');

        // Act
        $result = ($factory)($parameters);

        // Assert
        $this->assertSame($mockConnection, $result);
    }

    /**
     * Test that __invoke throws when URI scheme is missing.
     */
    public function testInvokeThrowsWhenSchemeIsMissing(): void
    {
        // Arrange
        $parameters = ['uri' => '/path/only']; // No scheme

        // Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('missing scheme');

        // Act
        ($this->factory)($parameters);
    }

    /**
     * Test that __invoke throws when URI scheme is unsupported.
     */
    public function testInvokeThrowsWhenSchemeIsUnsupported(): void
    {
        // Arrange
        $parameters = ['uri' => 'oracle://user:pass@localhost/db'];

        // Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unsupported DB URI scheme');

        // Act
        ($this->factory)($parameters);
    }

    public function testInvokeThrowsWhenConnectorMappingTypeIsInvalid(): void
    {
        $parameters = ['uri' => 'custom://user:pass@localhost/db'];
        $factory = new Connection();
        $this->injector->inject($factory, [
            'connectors' => ['custom' => 123],
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unsupported DB URI scheme');

        ($factory)($parameters);
    }
}
