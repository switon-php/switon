<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Core\Runtime;
use Switon\Db\ConnectionInterface;
use Switon\Db\PoolConfiguration;
use Switon\Db\PoolConfigurator;
use Switon\Db\Tests\TestCase;
use Switon\Pooling\PoolManagerInterface;
use stdClass;

/**
 * Unit tests for PoolConfigurator.
 */
#[AllowMockObjectsWithoutExpectations]
class PoolConfiguratorTest extends TestCase
{
    protected function tearDown(): void
    {
        Runtime::setCoroutineEnabled(false);
        parent::tearDown();
    }

    public function testConfigureParsesTimeoutPrefixAndPoolSize(): void
    {
        // Arrange
        Runtime::setCoroutineEnabled(false);

        $poolManager = $this->createMock(PoolManagerInterface::class);

        $poolManager->expects($this->once())
            ->method('add')
            ->with(
                $this->isInstanceOf(stdClass::class),
                $this->callback(function ($sample) {
                    return is_array($sample)
                        && ($sample[0] ?? null) === ConnectionInterface::class
                        && str_contains((string)($sample[1]['uri'] ?? ''), 'mysql://host1/db');
                }),
                8,
                'default'
            );

        $configurator = $this->createConfigurator($poolManager);
        $owner = new stdClass();

        // Act
        $config = $configurator->configure($owner, 'mysql://host1/db?timeout=5.5&pool_size=8&prefix=app_');

        // Assert
        $this->assertSame(5.5, $config->timeout);
        $this->assertSame('app_', $config->prefix);
        $this->assertFalse($config->hasSlave);
    }

    public function testConfigureAddsReadonlyToSlaveUrisInNonCoroutineMode(): void
    {
        // Arrange
        Runtime::setCoroutineEnabled(false);

        $poolManager = $this->createMock(PoolManagerInterface::class);

        // One master add + one randomly-selected slave add.
        $poolManager->expects($this->exactly(2))
            ->method('add')
            ->with(
                $this->isInstanceOf(stdClass::class),
                $this->callback(function ($sample) {
                    if (!is_array($sample)) {
                        return false;
                    }

                    if (($sample[0] ?? null) !== ConnectionInterface::class) {
                        return false;
                    }

                    $uri = (string)(($sample[1]['uri'] ?? ''));
                    // Master can be without readonly; slave must be readonly.
                    if (str_contains($uri, 'mysql://host1/db')) {
                        return true;
                    }

                    if (str_contains($uri, 'mysql://host2/db') || str_contains($uri, 'mysql://host3/db')) {
                        return str_contains($uri, 'readonly=1');
                    }

                    return false;
                }),
                $this->callback(function (int $size) {
                    return in_array($size, [1, 4, 2], true);
                }),
                $this->callback(function (string $type) {
                    return in_array($type, ['default', 'slave'], true);
                })
            );

        $configurator = $this->createConfigurator($poolManager);
        $owner = new stdClass();

        // Act
        $config = $configurator->configure($owner, 'mysql://host1/db,mysql://host2/db,mysql://host3/db?pool_size=4/2');

        // Assert
        $this->assertInstanceOf(PoolConfiguration::class, $config);
        $this->assertTrue($config->hasSlave);
    }

    public function testConfigureParsesBraceExpansionUriFormat(): void
    {
        // Arrange
        Runtime::setCoroutineEnabled(false);

        $poolManager = $this->createMock(PoolManagerInterface::class);

        $poolManager->expects($this->exactly(2))
            ->method('add')
            ->with(
                $this->isInstanceOf(stdClass::class),
                $this->callback(function ($sample) {
                    if (!is_array($sample)) {
                        return false;
                    }
                    if (($sample[0] ?? null) !== ConnectionInterface::class) {
                        return false;
                    }

                    $uri = (string)($sample[1]['uri'] ?? '');
                    return str_contains($uri, 'mysql://host1/db')
                        || (str_contains($uri, 'mysql://host2/db') && str_contains($uri, 'readonly=1'));
                }),
                $this->anything(),
                $this->callback(function (string $type) {
                    return in_array($type, ['default', 'slave'], true);
                })
            );

        $configurator = $this->createConfigurator($poolManager);
        $owner = new stdClass();

        // Act
        $config = $configurator->configure($owner, 'mysql://{host1,host2}/db?pool_size=1/1');

        // Assert
        $this->assertTrue($config->hasSlave);
    }

    public function testConfigureParsesCommaSeparatedHostsUriFormat(): void
    {
        // Arrange
        Runtime::setCoroutineEnabled(false);

        $poolManager = $this->createMock(PoolManagerInterface::class);

        $poolManager->expects($this->exactly(2))
            ->method('add')
            ->with(
                $this->isInstanceOf(stdClass::class),
                $this->callback(function ($sample) {
                    if (!is_array($sample)) {
                        return false;
                    }
                    if (($sample[0] ?? null) !== ConnectionInterface::class) {
                        return false;
                    }

                    $uri = (string)($sample[1]['uri'] ?? '');
                    return str_contains($uri, 'mysql://host1/db')
                        || (str_contains($uri, 'mysql://host2/db') && str_contains($uri, 'readonly=1'));
                }),
                $this->anything(),
                $this->callback(function (string $type) {
                    return in_array($type, ['default', 'slave'], true);
                })
            );

        $configurator = $this->createConfigurator($poolManager);
        $owner = new stdClass();

        // Act
        $config = $configurator->configure($owner, 'mysql://host1,host2/db?pool_size=1/1');

        // Assert
        $this->assertTrue($config->hasSlave);
    }

    public function testConfigureCreatesSlavePoolAndAddsAllSlavesInCoroutineMode(): void
    {
        // Arrange
        Runtime::setCoroutineEnabled(true);

        $poolManager = $this->createMock(PoolManagerInterface::class);

        $poolManager->expects($this->once())
            ->method('create')
            ->with(
                $this->isInstanceOf(stdClass::class),
                2 * 3,
                'slave'
            );

        // Calls:
        // - master add: size=4 (default)
        // - slave adds: slavePoolSize(3) * slaveCount(2) = 6, each size=1, type=slave
        $poolManager->expects($this->exactly(7))
            ->method('add')
            ->with(
                $this->isInstanceOf(stdClass::class),
                $this->callback(function ($sample) {
                    if (!is_array($sample)) {
                        return false;
                    }
                    if (($sample[0] ?? null) !== ConnectionInterface::class) {
                        return false;
                    }
                    $uri = (string)(($sample[1]['uri'] ?? ''));
                    if (str_contains($uri, 'mysql://host1/db')) {
                        return true;
                    }
                    if (str_contains($uri, 'mysql://host2/db') || str_contains($uri, 'mysql://host3/db')) {
                        return str_contains($uri, 'readonly=1');
                    }
                    return false;
                }),
                $this->callback(function (int $size) {
                    return in_array($size, [1, 4], true);
                }),
                $this->callback(function (string $type) {
                    return in_array($type, ['default', 'slave'], true);
                })
            );

        $configurator = $this->createConfigurator($poolManager);
        $owner = new stdClass();

        // Act
        $config = $configurator->configure($owner, 'mysql://host1/db,mysql://host2/db,mysql://host3/db?pool_size=4/3');

        // Assert
        $this->assertTrue($config->hasSlave);
    }

    public function testConfigureIgnoresEmptyUris(): void
    {
        // Arrange
        Runtime::setCoroutineEnabled(false);
        $poolManager = $this->createMock(PoolManagerInterface::class);

        // Should not call add() if URI is empty string
        $poolManager->expects($this->never())->method('add');

        $configurator = $this->createConfigurator($poolManager);
        $owner = new stdClass();

        // Act
        $config = $configurator->configure($owner, '');

        // Assert
        $this->assertFalse($config->hasSlave);
    }

    public function testConfigureIgnoresEmptySegmentsInCommaSeparatedUri(): void
    {
        // Arrange
        Runtime::setCoroutineEnabled(false);
        $poolManager = $this->createMock(PoolManagerInterface::class);

        // Should only add valid non-empty URIs
        $poolManager->expects($this->once())
            ->method('add')
            ->with($this->anything(), $this->callback(function ($def) {
                return str_contains($def[1]['uri'], 'mysql://host1');
            }));

        $configurator = $this->createConfigurator($poolManager);
        $owner = new stdClass();

        // Act
        // "mysql://host1/db,,," -> parsing might split empty strings
        $config = $configurator->configure($owner, 'mysql://host1/db,,,');

        // Assert
        $this->assertFalse($config->hasSlave);
    }

    public function testConfigureUsesConfiguredParserMapForUriListFormat(): void
    {
        // Arrange
        Runtime::setCoroutineEnabled(false);
        $poolManager = $this->createMock(PoolManagerInterface::class);

        $poolManager->expects($this->once())
            ->method('add')
            ->with(
                $this->isInstanceOf(stdClass::class),
                $this->callback(function ($definition) {
                    return is_array($definition)
                        && ($definition[0] ?? null) === ConnectionInterface::class
                        && ($definition[1]['uri'] ?? null)
                        === 'mysql://master/db,mysql://slave/db';
                }),
                4,
                'default'
            );

        $configurator = $this->createConfigurator($poolManager, [
            'brace' => 'parseBraceUris',
            'hosts' => 'parseHostUris',
            // Force URI list format to be treated as single URI.
            'list' => 'parseSingleUri',
            'single' => 'parseSingleUri',
        ]);
        $owner = new stdClass();

        // Act
        $config = $configurator->configure($owner, 'mysql://master/db,mysql://slave/db');

        // Assert
        $this->assertFalse($config->hasSlave);
    }

    public function testConfigureFallsBackWhenConfiguredParserIsInvalid(): void
    {
        // Arrange
        Runtime::setCoroutineEnabled(false);
        $poolManager = $this->createMock(PoolManagerInterface::class);

        $poolManager->expects($this->once())
            ->method('add')
            ->with(
                $this->isInstanceOf(stdClass::class),
                $this->callback(function ($definition) {
                    return is_array($definition)
                        && ($definition[0] ?? null) === ConnectionInterface::class
                        // Invalid parser mapping should fall back to a single URI.
                        && ($definition[1]['uri'] ?? null)
                        === 'mysql://master/db,mysql://slave/db';
                }),
                4,
                'default'
            );

        $configurator = $this->createConfigurator($poolManager, [
            'brace' => 'parseBraceUris',
            'hosts' => 'parseHostUris',
            'list' => 'parseMissingUris',
            'single' => 'parseSingleUri',
        ]);
        $owner = new stdClass();

        // Act
        $config = $configurator->configure($owner, 'mysql://master/db,mysql://slave/db');

        // Assert
        $this->assertFalse($config->hasSlave);
    }

    /**
     * Create PoolConfigurator with injected PoolManagerInterface mock.
     *
     * @param array<string, string>|null $parsers
     */
    protected function createConfigurator(PoolManagerInterface $poolManager, ?array $parsers = null): PoolConfigurator
    {
        $configurator = new PoolConfigurator();
        $parameters = ['poolManager' => $poolManager];
        if ($parsers !== null) {
            $parameters['parsers'] = $parsers;
        }

        $this->injector->inject($configurator, $parameters);

        return $configurator;
    }
}
