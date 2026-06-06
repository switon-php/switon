<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Db\ClientInterface;
use Switon\Db\TableScanner;
use Switon\Db\TableScannerInterface;
use Switon\Db\Tests\TestCase;
use Switon\Di\NamedLookupInterface;

/**
 * Unit tests for TableScanner.
 */
#[AllowMockObjectsWithoutExpectations]
class TableScannerTest extends TestCase
{
    public function testScanSpecificConnectionReturnsExpectedStructure(): void
    {
        // Arrange
        $namedLookup = $this->createMock(NamedLookupInterface::class);
        $client = $this->createMock(ClientInterface::class);

        $namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($client);

        $client->expects($this->once())
            ->method('getTables')
            ->willReturn(['users', 'roles']);

        $scanner = $this->createScanner($namedLookup, []);
        // Act

        $result = $scanner->scan('default');
        // Assert

        $this->assertSame(['default' => ['users', 'roles']], $result);
    }

    public function testScanAllConnectionsUsesNamedLookupNames(): void
    {
        // Arrange
        $namedLookup = $this->createMock(NamedLookupInterface::class);

        $clientDefault = $this->createMock(ClientInterface::class);
        $clientRead = $this->createMock(ClientInterface::class);

        $namedLookup->expects($this->once())
            ->method('names')
            ->with(ClientInterface::class)
            ->willReturn(['default', 'read']);

        $namedLookup->expects($this->exactly(2))
            ->method('by')
            ->willReturnMap([
                [ClientInterface::class, 'default', $clientDefault],
                [ClientInterface::class, 'read', $clientRead],
            ]);

        $clientDefault->expects($this->once())
            ->method('getTables')
            ->willReturn(['users']);

        $clientRead->expects($this->once())
            ->method('getTables')
            ->willReturn(['logs']);

        $scanner = $this->createScanner($namedLookup, []);

        $result = $scanner->scan();
        // Assert

        $this->assertSame(
            [
                'default' => ['users'],
                'read' => ['logs'],
            ],
            $result
        );
    }

    public function testBlacklistFiltersTablesAndReindexes(): void
    {
        // Arrange
        $namedLookup = $this->createMock(NamedLookupInterface::class);
        $client = $this->createMock(ClientInterface::class);

        $namedLookup->method('by')->willReturn($client);

        // Non-sequential keys ensure array_values() behavior is validated.
        $client->method('getTables')->willReturn([
            2 => 'users',
            5 => 'migration_20250101_init',
            9 => 'tmp_table',
            11 => 'orders',
        ]);

        $scanner = $this->createScanner($namedLookup, ['migration_*', 'tmp_*']);
        // Act

        $result = $scanner->scan('default');
        // Assert

        $this->assertSame(['default' => ['users', 'orders']], $result);
        $this->assertSame([0, 1], array_keys($result['default']));
    }

    public function testBlacklistSupportsExactMatch(): void
    {
        // Arrange
        $namedLookup = $this->createMock(NamedLookupInterface::class);
        $client = $this->createMock(ClientInterface::class);

        $namedLookup->method('by')->willReturn($client);
        $client->method('getTables')->willReturn(['users', 'roles', 'permissions']);

        $scanner = $this->createScanner($namedLookup, ['roles']);
        // Act

        $result = $scanner->scan('default');
        // Assert

        $this->assertSame(['default' => ['users', 'permissions']], $result);
    }

    protected function createScanner(NamedLookupInterface $namedLookup, array $blacklist): TableScannerInterface
    {
        // Use container's make() to create TableScanner with dependencies injected
        return $this->make(TableScanner::class, [
            'namedLookup' => $namedLookup,
            'blacklist' => $blacklist,
        ]);
    }
}
