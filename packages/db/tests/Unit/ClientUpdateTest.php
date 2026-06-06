<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Db\Client;
use Switon\Db\ConnectionInterface;
use Switon\Db\PoolConfiguration;
use Switon\Db\PoolConfigurator;
use Switon\Db\SqlFragmentInterface;
use Switon\Db\Tests\TestCase;
use Switon\Pooling\PoolGuard;
use Switon\Pooling\PoolManagerInterface;

/**
 * Test cases for Client::update() method.
 *
 * Specifically tests SqlFragmentInterface bind value handling.
 */
#[AllowMockObjectsWithoutExpectations]
class ClientUpdateTest extends TestCase
{
    protected EventDispatcherInterface|MockObject $eventDispatcher;
    protected PoolManagerInterface|MockObject $poolManager;
    protected PoolConfigurator|MockObject $poolConfigurator;
    protected ConnectionInterface|MockObject $connection;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->poolManager = $this->createMock(PoolManagerInterface::class);
        $this->poolConfigurator = $this->createMock(PoolConfigurator::class);
        $this->connection = $this->createMock(ConnectionInterface::class);

        // Configure pool configurator to return default config
        $config = new PoolConfiguration(
            timeout: 1.0,
            prefix: '',
            hasSlave: false,
        );
        $this->poolConfigurator->method('configure')->willReturn($config);

        // Register mocks in container
        $this->container->set(EventDispatcherInterface::class, $this->eventDispatcher);
        $this->container->set(PoolManagerInterface::class, $this->poolManager);
        $this->container->set(PoolConfigurator::class, $this->poolConfigurator);

        // Create client
        $this->client = $this->container->make(Client::class, ['uri' => 'mysql://localhost/test']);
    }

    /**
     * Test that update() merges SqlFragmentInterface bind values into the SQL bind array.
     *
     * This test verifies the fix for the bug where SqlFragmentInterface objects had their
     * getBind() method called but the returned bind values were not merged into the final
     * SQL bind array, causing parameterized values to be missing from the executed query.
     */
    public function testUpdateMergesQueryFragmentableBindValues(): void
    {
        // Create a mock SqlFragmentInterface object
        $fragment = new class () implements SqlFragmentInterface {
            protected string $field = '';

            public function setField(string $field): static
            {
                $this->field = $field;
                return $this;
            }

            public function getExpression(): string
            {
                return "{$this->field} = {$this->field} + :{$this->field}";
            }

            public function getBind(): array
            {
                return [$this->field => 5];
            }
        };

        // Set up connection mock to capture the executed SQL and bind values
        $capturedSql = null;
        $capturedBind = null;

        $this->connection->method('convertQuotes')
            ->willReturnCallback(fn ($sql) => str_replace(['[', ']'], '`', $sql));

        $this->connection->method('execute')
            ->willReturnCallback(function ($sql, $bind) use (&$capturedSql, &$capturedBind) {
                $capturedSql = $sql;
                $capturedBind = $bind;
                return 1;
            });

        // Set up pool manager to return guard with our mock connection
        $guard = new PoolGuard(
            $this->poolManager,
            $this->client,
            $this->connection,
            'default'
        );

        $this->poolManager->method('guard')
            ->willReturn($guard);

        // Create transient client for update
        $transient = $this->client->getTransient();

        // Execute update with SqlFragmentInterface object
        $transient->update('users', ['counter' => $fragment], ['id' => 123]);

        // Verify the bind array contains the fragment's bind values
        $this->assertNotNull($capturedBind, 'Bind array should not be null');
        $this->assertArrayHasKey('counter', $capturedBind, 'Bind array should contain fragment bind key');
        $this->assertSame(5, $capturedBind['counter'], 'Fragment bind value should be 5');
        $this->assertArrayHasKey('id', $capturedBind, 'Bind array should contain condition bind key');
        $this->assertSame(123, $capturedBind['id'], 'Condition bind value should be 123');

        // Verify SQL contains the expression
        $this->assertStringContainsString('counter = counter + :counter', $capturedSql);
    }

    /**
     * Test that update() handles multiple SqlFragmentInterface objects correctly.
     */
    public function testUpdateHandlesMultipleQueryFragmentableObjects(): void
    {
        // Create two fragment objects
        $incrementFragment = new class () implements SqlFragmentInterface {
            protected string $field = '';

            public function setField(string $field): static
            {
                $this->field = $field;
                return $this;
            }

            public function getExpression(): string
            {
                return "{$this->field} = {$this->field} + :{$this->field}";
            }

            public function getBind(): array
            {
                return [$this->field => 1];
            }
        };

        $decrementFragment = new class () implements SqlFragmentInterface {
            protected string $field = '';

            public function setField(string $field): static
            {
                $this->field = $field;
                return $this;
            }

            public function getExpression(): string
            {
                return "{$this->field} = {$this->field} - :{$this->field}";
            }

            public function getBind(): array
            {
                return [$this->field => 10];
            }
        };

        $capturedBind = null;

        $this->connection->method('convertQuotes')
            ->willReturnCallback(fn ($sql) => str_replace(['[', ']'], '`', $sql));

        $this->connection->method('execute')
            ->willReturnCallback(function ($sql, $bind) use (&$capturedBind) {
                $capturedBind = $bind;
                return 1;
            });

        $guard = new PoolGuard(
            $this->poolManager,
            $this->client,
            $this->connection,
            'default'
        );

        $this->poolManager->method('guard')
            ->willReturn($guard);

        $transient = $this->client->getTransient();

        // Execute update with multiple SqlFragmentInterface objects
        $transient->update('stats', [
            'view_count' => $incrementFragment,
            'stock' => $decrementFragment,
        ], ['id' => 1]);

        // Verify all fragment bind values are present
        $this->assertArrayHasKey('view_count', $capturedBind);
        $this->assertSame(1, $capturedBind['view_count']);
        $this->assertArrayHasKey('stock', $capturedBind);
        $this->assertSame(10, $capturedBind['stock']);
        $this->assertArrayHasKey('id', $capturedBind);
    }

    /**
     * Test that update() handles SqlFragmentInterface with empty bind array.
     */
    public function testUpdateHandlesQueryFragmentableWithEmptyBind(): void
    {
        // Fragment that returns empty bind (e.g., raw SQL expression)
        $rawFragment = new class () implements SqlFragmentInterface {
            protected string $field = '';

            public function setField(string $field): static
            {
                $this->field = $field;
                return $this;
            }

            public function getExpression(): string
            {
                return "{$this->field} = NOW()";
            }

            public function getBind(): array
            {
                return []; // No bind values for raw expression
            }
        };

        $capturedBind = null;

        $this->connection->method('convertQuotes')
            ->willReturnCallback(fn ($sql) => str_replace(['[', ']'], '`', $sql));

        $this->connection->method('execute')
            ->willReturnCallback(function ($sql, $bind) use (&$capturedBind) {
                $capturedBind = $bind;
                return 1;
            });

        $guard = new PoolGuard(
            $this->poolManager,
            $this->client,
            $this->connection,
            'default'
        );

        $this->poolManager->method('guard')
            ->willReturn($guard);

        $transient = $this->client->getTransient();

        // Execute update
        $transient->update('users', ['updated_at' => $rawFragment], ['id' => 1]);

        // Verify only condition bind is present (no fragment bind)
        $this->assertArrayHasKey('id', $capturedBind);
        $this->assertCount(1, $capturedBind, 'Only condition bind should be present');
    }

    /**
     * Test that update() handles mixed regular values and SqlFragmentInterface objects.
     */
    public function testUpdateHandlesMixedValuesAndFragments(): void
    {
        $fragment = new class () implements SqlFragmentInterface {
            protected string $field = '';

            public function setField(string $field): static
            {
                $this->field = $field;
                return $this;
            }

            public function getExpression(): string
            {
                return "{$this->field} = {$this->field} + :{$this->field}";
            }

            public function getBind(): array
            {
                return [$this->field => 1];
            }
        };

        $capturedBind = null;

        $this->connection->method('convertQuotes')
            ->willReturnCallback(fn ($sql) => str_replace(['[', ']'], '`', $sql));

        $this->connection->method('execute')
            ->willReturnCallback(function ($sql, $bind) use (&$capturedBind) {
                $capturedBind = $bind;
                return 1;
            });

        $guard = new PoolGuard(
            $this->poolManager,
            $this->client,
            $this->connection,
            'default'
        );

        $this->poolManager->method('guard')
            ->willReturn($guard);

        $transient = $this->client->getTransient();

        // Mixed regular values and fragment
        $transient->update('users', [
            'name' => 'John',
            'login_count' => $fragment,
            'status' => 'active',
        ], ['id' => 123]);

        // Verify all bind values present
        $this->assertArrayHasKey('name', $capturedBind);
        $this->assertSame('John', $capturedBind['name']);
        $this->assertArrayHasKey('login_count', $capturedBind);
        $this->assertSame(1, $capturedBind['login_count']);
        $this->assertArrayHasKey('status', $capturedBind);
        $this->assertSame('active', $capturedBind['status']);
        $this->assertArrayHasKey('id', $capturedBind);
        $this->assertSame(123, $capturedBind['id']);
    }
}
