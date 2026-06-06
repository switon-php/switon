<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Unit;

use Switon\Routing\Tests\TestCase;

/**
 * Test cases for Router prefix functionality.
 *
 * Tests prefix management: setPrefix(), getPrefix(), conditional prefixes.
 */
class RouterPrefixTest extends TestCase
{
    /**
     * Test setting and getting static prefix.
     *
     * Verifies that static prefixes can be set and retrieved correctly.
     */
    public function testSetAndGetStaticPrefix(): void
    {
        // Arrange
        $prefix = '/api/v1';

        // Act
        $this->router->setPrefix($prefix);
        $result = $this->router->getPrefix();

        // Assert
        $this->assertSame($prefix, $result, 'Router should return the same prefix that was set');
    }

    /**
     * Test setPrefix returns self for chaining.
     *
     * Verifies that setPrefix() returns the router instance for method chaining.
     */
    public function testSetPrefixReturnsSelf(): void
    {
        // Act
        $result = $this->router->setPrefix('/api/v1');

        // Assert
        $this->assertSame($this->router, $result, 'setPrefix() should return self for method chaining');
    }

    /**
     * Test prefix is applied to route matching.
     *
     * Verifies that routes are matched with prefix consideration.
     */
    public function testPrefixAppliedToRouteMatching(): void
    {
        // Arrange
        $this->router->setPrefix('/api/v1');
        $this->router->addGet('/users', 'UserController::index');

        // Act
        $matcher = $this->router->match('/api/v1/users', 'GET');

        // Assert
        $this->assertNotNull($matcher, 'Router should match route with prefix');
        $this->assertSame('UserController::index', $matcher->getHandler());
    }

    /**
     * Prefix mismatch raises {@see \Switon\Routing\Exception\NotFoundRouteException}.
     */
    public function testPrefixMismatchThrowsNotFoundRouteException(): void
    {
        // Arrange
        $this->router->setPrefix('/api/v1');
        $this->router->addGet('/users', 'UserController::index');

        // Act & Assert
        $this->expectException(\Switon\Routing\Exception\NotFoundRouteException::class);
        $this->router->match('/api/v2/users', 'GET');
    }

    /**
     * Test empty prefix disables prefix matching.
     *
     * Verifies that setting empty prefix allows routes to match without prefix.
     */
    public function testEmptyPrefixDisablesPrefix(): void
    {
        // Arrange
        $this->router->setPrefix('/api/v1');
        $this->router->setPrefix('');
        $this->router->addGet('/users', 'UserController::index');

        // Act
        $matcher = $this->router->match('/users', 'GET');

        // Assert
        $this->assertNotNull($matcher, 'Router should match route without prefix when prefix is empty');
        $this->assertSame('UserController::index', $matcher->getHandler());
    }

    /**
     * Test prefix with conditional pattern extraction.
     *
     * Verifies that conditional prefixes (starting with ?) extract the full matched prefix from the URI.
     */
    public function testConditionalPrefixExtractsFromUri(): void
    {
        // Arrange
        $this->router->setPrefix('?/v\d+');

        // Act
        $prefix = $this->router->getPrefix('/tenant/v2/users');

        // Assert
        $this->assertSame('/tenant/v2', $prefix, 'Router should extract prefix from URI when using conditional prefix');
    }

    /**
     * Test conditional prefix throws when URI provided but does not match pattern.
     */
    public function testConditionalPrefixUriMismatchThrows(): void
    {
        $this->router->setPrefix('?/v\d+');
        $this->expectException(\Switon\Routing\Exception\NotFoundRouteException::class);
        $this->router->getPrefix('/users');
    }

    /**
     * Test conditional prefix without URI returns configured prefix (e.g. for router:prefix CLI).
     */
    public function testConditionalPrefixWithoutUriReturnsConfigured(): void
    {
        $this->router->setPrefix('?/v\d+');
        $this->assertSame('?/v\d+', $this->router->getPrefix());
    }

    /**
     * Test prefix with dynamic route matching.
     *
     * Verifies that prefix works correctly with dynamic (parameterized) routes.
     */
    public function testPrefixWithDynamicRouteMatching(): void
    {
        // Arrange
        $this->router->setPrefix('/api');
        $this->router->addGet('/users/{id}', 'UserController::show');

        // Act
        $matcher = $this->router->match('/api/users/123', 'GET');

        // Assert
        $this->assertNotNull($matcher, 'Router should match dynamic route with prefix');
        $this->assertSame('UserController::show', $matcher->getHandler());
        $this->assertSame('123', $matcher->getVariables()['id']);
    }
}
