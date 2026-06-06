<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Integration;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Core\InjectorInterface;
use Switon\Db\Connection;
use Switon\Db\Connection\Adapter\Mysql;
use Switon\Db\Tests\TestCase;

/**
 * Test cases for Connection factory class.
 *
 * Tests connection factory functionality.
 */
#[AllowMockObjectsWithoutExpectations]
class ConnectionTest extends TestCase
{
    /**
     * Creates a Connection instance for testing.
     *
     * Note: Cannot use container->make() because Connection has __invoke,
     * and container treats __invoke classes as factories.
     */
    protected function createConnectionForTesting(): Connection
    {
        $connection = new Connection();
        $this->container->get(InjectorInterface::class)->inject($connection);
        return $connection;
    }

    /**
     * Test that __invoke() creates MySQL adapter from URI.
     */
    public function testInvokeCreatesMysqlAdapterFromUri(): void
    {
        $connection = $this->createConnectionForTesting();

        $mockAdapter = $this->createMock(Mysql::class);

        // Register factory that returns mock adapter
        $factory = new class ($mockAdapter) {
            public function __construct(private $adapter)
            {
            }

            public function __invoke(array $parameters = []): mixed
            {
                return $this->adapter;
            }
        };
        $this->container->set(Mysql::class, $factory);

        $result = $connection(['uri' => 'mysql://host/db'], 'test.id');

        $this->assertSame($mockAdapter, $result);
    }

    /**
     * Test that __invoke() uses first parameter as URI when uri key not present.
     */
    public function testInvokeUsesFirstParameterAsUriWhenUriKeyNotPresent(): void
    {
        $connection = $this->createConnectionForTesting();

        $mockAdapter = $this->createMock(Mysql::class);

        // Register factory that returns mock adapter
        $factory = new class ($mockAdapter) {
            public function __construct(private $adapter)
            {
            }

            public function __invoke(array $parameters = []): mixed
            {
                return $this->adapter;
            }
        };
        $this->container->set(Mysql::class, $factory);

        $result = $connection(['mysql://host/db'], 'test.id');

        $this->assertSame($mockAdapter, $result);
    }

    /**
     * Test that __invoke() creates SQLite adapter from URI.
     */
    public function testInvokeCreatesSqliteAdapterFromUri(): void
    {
        $connection = $this->createConnectionForTesting();

        $mockAdapter = $this->createMock(\Switon\Db\Connection\Adapter\Sqlite::class);

        // Register factory that returns mock adapter
        $factory = new class ($mockAdapter) {
            public function __construct(private $adapter)
            {
            }

            public function __invoke(array $parameters = []): mixed
            {
                return $this->adapter;
            }
        };
        $this->container->set(\Switon\Db\Connection\Adapter\Sqlite::class, $factory);

        // Use sqlite: URI format that parse_url can handle (sqlite:/path or sqlite::memory:)
        $result = $connection(['uri' => 'sqlite::memory:'], 'test.id');

        $this->assertSame($mockAdapter, $result);
    }
}
