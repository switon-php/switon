<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\CollectorInterface;
use Switon\Core\ContainerInterface;
use Switon\Inspector\CollectorDiscovery;
use Switon\Inspector\Tests\TestCase;
use ArrayIterator;

#[AllowMockObjectsWithoutExpectations]
class CollectorDiscoveryTest extends TestCase
{
    protected ComposerExtraInterface $composerExtra;
    protected ContainerInterface $containerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->composerExtra = $this->createMock(ComposerExtraInterface::class);
        $this->container->replace(ComposerExtraInterface::class, $this->composerExtra);

        // Create fresh container mock for each test to avoid call history issues
        $this->containerMock = $this->createMock(ContainerInterface::class);
        $this->container->replace(ContainerInterface::class, $this->containerMock);
    }

    /**
     * @param iterable<string, string|null> $collectors
     */
    protected function makeDiscovery(iterable $collectors = [], bool $autoDiscover = true): CollectorDiscovery
    {
        return $this->container->make(CollectorDiscovery::class, [
            'collectors' => $collectors,
            'autoDiscover' => $autoDiscover,
        ]);
    }

    public function testResolveAcceptsTraversableDefinitions(): void
    {
        $collector = $this->createMock(CollectorInterface::class);
        $this->composerExtra->method('all')->willReturn([]);

        $this->containerMock->expects($this->once())
            ->method('get')
            ->with('IteratorCollector')
            ->willReturn($collector);

        $discovery = $this->makeDiscovery(new ArrayIterator([
            'iterable' => 'IteratorCollector',
        ]), false);

        $result = $discovery->resolve();

        $this->assertArrayHasKey('iterable', $result);
        $this->assertSame($collector, $result['iterable']);
    }

    public function testResolveReturnsCachedResultOnSecondCall(): void
    {
        // Arrange
        $collector = $this->createMock(CollectorInterface::class);
        $this->composerExtra->method('all')->willReturn([
            'switon/test' => [
                'switon' => [
                    'collectors' => [
                        'test' => 'TestCollector',
                    ],
                ],
            ],
        ]);

        $this->containerMock->expects($this->once())
            ->method('get')
            ->with('TestCollector')
            ->willReturn($collector);

        $discovery = $this->makeDiscovery();

        // Act
        $result1 = $discovery->resolve();
        $result2 = $discovery->resolve();

        // Assert
        $this->assertSame($result1, $result2);
        $this->assertArrayHasKey('test', $result1);
        $this->assertSame($collector, $result1['test']);
    }

    public function testResolveDiscoversFromComposerWhenAutoDiscoverIsTrue(): void
    {
        // Arrange
        $collector = $this->createMock(CollectorInterface::class);
        $this->composerExtra->method('all')->willReturn([
            'switon/db' => [
                'switon' => [
                    'collectors' => [
                        'sql' => 'Switon\\Db\\Inspector\\SqlCollector',
                    ],
                ],
            ],
            'switon/log' => [
                'switon' => [
                    'collectors' => [
                        'logger' => 'Switon\\Log\\Inspector\\LoggerCollector',
                    ],
                ],
            ],
        ]);

        $this->containerMock->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function ($class) use ($collector) {
                return match ($class) {
                    'Switon\\Db\\Inspector\\SqlCollector',
                    'Switon\\Log\\Inspector\\LoggerCollector' => $collector,
                    default => $this->createMock(CollectorInterface::class),
                };
            });

        $discovery = $this->makeDiscovery([], true);

        // Act
        $result = $discovery->resolve();

        // Assert
        $this->assertArrayHasKey('sql', $result);
        $this->assertArrayHasKey('logger', $result);
        $this->assertSame($collector, $result['sql']);
        $this->assertSame($collector, $result['logger']);
    }

    public function testResolveSkipsDiscoveryWhenAutoDiscoverIsFalse(): void
    {
        // Arrange
        $this->composerExtra->expects($this->never())->method('all');

        $discovery = $this->makeDiscovery([], false);

        // Act
        $result = $discovery->resolve();

        // Assert
        $this->assertEmpty($result);
    }

    public function testResolveMergesUserConfigWithDiscovered(): void
    {
        // Arrange
        $sqlCollector = $this->createMock(CollectorInterface::class);
        $customCollector = $this->createMock(CollectorInterface::class);

        $this->composerExtra->method('all')->willReturn([
            'switon/db' => [
                'switon' => [
                    'collectors' => [
                        'sql' => 'Switon\\Db\\Inspector\\SqlCollector',
                    ],
                ],
            ],
        ]);

        $callCount = 0;
        $this->containerMock->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function ($class) use ($sqlCollector, $customCollector, &$callCount) {
                $callCount++;
                return match ($class) {
                    'Switon\\Db\\Inspector\\SqlCollector' => $sqlCollector,
                    'CustomCollector' => $customCollector,
                    default => $this->createMock(CollectorInterface::class),
                };
            });

        $discovery = $this->makeDiscovery([
            'custom' => 'CustomCollector',
        ]);

        // Act
        $result = $discovery->resolve();

        // Assert
        $this->assertArrayHasKey('sql', $result);
        $this->assertArrayHasKey('custom', $result);
        $this->assertSame($sqlCollector, $result['sql']);
        $this->assertSame($customCollector, $result['custom']);
    }

    public function testResolveUserConfigOverridesDiscovered(): void
    {
        // Arrange
        $overriddenCollector = $this->createMock(CollectorInterface::class);

        $this->composerExtra->method('all')->willReturn([
            'switon/db' => [
                'switon' => [
                    'collectors' => [
                        'sql' => 'Switon\\Db\\Inspector\\SqlCollector',
                    ],
                ],
            ],
        ]);

        $this->containerMock->expects($this->once())
            ->method('get')
            ->with('CustomSqlCollector')
            ->willReturn($overriddenCollector);

        $discovery = $this->makeDiscovery([
            'sql' => 'CustomSqlCollector', // Override discovered collector
        ]);

        // Act
        $result = $discovery->resolve();

        // Assert
        $this->assertArrayHasKey('sql', $result);
        $this->assertSame($overriddenCollector, $result['sql']);
        $this->assertCount(1, $result);
    }

    public function testResolveFiltersDisabledCollectors(): void
    {
        // Arrange
        $sqlCollector = $this->createMock(CollectorInterface::class);

        $this->composerExtra->method('all')->willReturn([
            'switon/db' => [
                'switon' => [
                    'collectors' => [
                        'sql' => 'Switon\\Db\\Inspector\\SqlCollector',
                        'logger' => 'Switon\\Log\\Inspector\\LoggerCollector',
                    ],
                ],
            ],
        ]);

        // Create a completely fresh container mock to avoid any call history
        $freshContainerMock = $this->createMock(ContainerInterface::class);
        $this->container->replace(ContainerInterface::class, $freshContainerMock);

        // Only sql collector should be resolved (logger is disabled via null)
        // array_merge will set logger => null, which should be filtered out in the loop
        $freshContainerMock->expects($this->once())
            ->method('get')
            ->with('Switon\\Db\\Inspector\\SqlCollector')
            ->willReturn($sqlCollector);

        // Create discovery with logger disabled (null means disable)
        $discovery = $this->container->make(CollectorDiscovery::class, [
            'collectors' => [
                'logger' => null, // Disable logger collector - null should filter it out
            ],
            'autoDiscover' => true,
        ]);

        // Act
        $result = $discovery->resolve();

        // Assert
        $this->assertArrayHasKey('sql', $result);
        $this->assertArrayNotHasKey('logger', $result);
        $this->assertCount(1, $result);
    }

    public function testDiscoverFromComposerSkipsInvalidEntries(): void
    {
        // Arrange
        $collector = $this->createMock(CollectorInterface::class);

        $this->composerExtra->method('all')->willReturn([
            'switon/db' => [
                'switon' => [
                    'collectors' => [
                        'sql' => 'Switon\\Db\\Inspector\\SqlCollector',
                        'invalid-key' => 123, // Invalid: not a string
                        'empty' => '', // Invalid: empty string
                        0 => 'NumericKeyCollector', // Invalid: numeric key
                    ],
                ],
            ],
            'switon/invalid' => [
                'switon' => [
                    'collectors' => 'not-an-array', // Invalid: not an array
                ],
            ],
            'switon/no-collectors' => [
                'switon' => [
                    'other' => 'data',
                ],
            ],
        ]);

        $this->containerMock->expects($this->once())
            ->method('get')
            ->with('Switon\\Db\\Inspector\\SqlCollector')
            ->willReturn($collector);

        $discovery = $this->makeDiscovery();

        // Act
        $result = $discovery->resolve();

        // Assert
        $this->assertArrayHasKey('sql', $result);
        $this->assertArrayNotHasKey('invalid-key', $result);
        $this->assertArrayNotHasKey('empty', $result);
        $this->assertArrayNotHasKey(0, $result);
        $this->assertCount(1, $result);
    }

    public function testDiscoverFromComposerHandlesMultiplePackages(): void
    {
        // Arrange
        $sqlCollector = $this->createMock(CollectorInterface::class);
        $loggerCollector = $this->createMock(CollectorInterface::class);

        $this->composerExtra->method('all')->willReturn([
            'switon/db' => [
                'switon' => [
                    'collectors' => [
                        'sql' => 'Switon\\Db\\Inspector\\SqlCollector',
                    ],
                ],
            ],
            'switon/log' => [
                'switon' => [
                    'collectors' => [
                        'logger' => 'Switon\\Log\\Inspector\\LoggerCollector',
                    ],
                ],
            ],
            'switon/event' => [
                'switon' => [
                    'collectors' => [
                        'events' => 'Switon\\Event\\Inspector\\EventCollector',
                    ],
                ],
            ],
        ]);

        $this->containerMock->expects($this->exactly(3))
            ->method('get')
            ->willReturnCallback(function ($class) use ($sqlCollector, $loggerCollector) {
                return match ($class) {
                    'Switon\\Db\\Inspector\\SqlCollector' => $sqlCollector,
                    'Switon\\Log\\Inspector\\LoggerCollector' => $loggerCollector,
                    'Switon\\Event\\Inspector\\EventCollector' => $this->createMock(CollectorInterface::class),
                    default => $this->createMock(CollectorInterface::class),
                };
            });

        $discovery = $this->makeDiscovery();

        // Act
        $result = $discovery->resolve();

        // Assert
        $this->assertCount(3, $result);
        $this->assertArrayHasKey('sql', $result);
        $this->assertArrayHasKey('logger', $result);
        $this->assertArrayHasKey('events', $result);
    }

    public function testResolveHandlesEmptyDiscovery(): void
    {
        // Arrange
        $this->composerExtra->method('all')->willReturn([]);

        $discovery = $this->makeDiscovery();

        // Act
        $result = $discovery->resolve();

        // Assert
        $this->assertEmpty($result);
    }
}
