<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Unit;

use Switon\Routing\MatcherInterface;
use Switon\Routing\Tests\TestCase;

/**
 * Test cases for Router basic operations.
 *
 * Tests basic route registration and matching: add(), addGet(), addPost(), match().
 */
class RouterBasicTest extends TestCase
{
    /**
     * Test basic route registration with GET method.
     *
     * Verifies that routes can be registered using addGet()
     * and matched correctly.
     */
    public function testAddGetRoute(): void
    {
        // Arrange
        $this->router->addGet('/users', 'UserController::index');

        // Act
        $matcher = $this->router->match('/users', 'GET');

        // Assert
        $this->assertNotNull($matcher, 'Router should match the registered GET route');
        $this->assertInstanceOf(MatcherInterface::class, $matcher);
        $this->assertSame('UserController::index', $matcher->getHandler());
        $this->assertSame([], $matcher->getVariables());
    }

    /**
     * Test basic route registration with POST method.
     *
     * Verifies that routes can be registered using addPost()
     * and matched correctly.
     */
    public function testAddPostRoute(): void
    {
        // Arrange
        $this->router->addPost('/users', 'UserController::create');

        // Act
        $matcher = $this->router->match('/users', 'POST');

        // Assert
        $this->assertNotNull($matcher, 'Router should match the registered POST route');
        $this->assertSame('UserController::create', $matcher->getHandler());
    }

    /**
     * Test route registration with array handler format.
     *
     * Verifies that routes can be registered with array handler format
     * and converted to string format internally.
     */
    public function testAddRouteWithArrayHandler(): void
    {
        // Arrange
        $this->router->add('GET', '/users', ['UserController', 'index']);

        // Act
        $matcher = $this->router->match('/users', 'GET');

        // Assert
        $this->assertNotNull($matcher);
        $this->assertSame('UserController::index', $matcher->getHandler());
    }

    /**
     * Test route matching with wildcard method.
     *
     * Verifies that routes registered with addAny() match all HTTP methods.
     */
    public function testAddAnyRoute(): void
    {
        // Arrange
        $this->router->addAny('/health', 'HealthController::check');

        // Act
        $matcher = $this->router->match('/health', 'GET');

        // Assert
        $this->assertNotNull($matcher, 'Router should match wildcard route for GET method');
        $this->assertSame('HealthController::check', $matcher->getHandler());

        // Test with POST method
        $matcher = $this->router->match('/health', 'POST');
        $this->assertNotNull($matcher, 'Router should match wildcard route for POST method');
    }

    /**
     * Unmatched path raises {@see \Switon\Routing\Exception\NotFoundRouteException}.
     */
    public function testMatchUnmatchedRouteThrowsNotFoundRouteException(): void
    {
        // Arrange
        $this->router->addGet('/users', 'UserController::index');

        // Act & Assert
        $this->expectException(\Switon\Routing\Exception\NotFoundRouteException::class);
        $this->router->match('/posts', 'GET');
    }

    /**
     * Test route matching with different HTTP methods.
     *
     * Verifies that routes are matched by both path and HTTP method.
     */
    public function testMatchRespectsHttpMethod(): void
    {
        // Arrange
        $this->router->addGet('/users', 'UserController::index');
        $this->router->addPost('/users', 'UserController::create');

        // Test GET method
        $matcher = $this->router->match('/users', 'GET');
        $this->assertNotNull($matcher);
        $this->assertSame('UserController::index', $matcher->getHandler());

        // Test POST method
        $matcher = $this->router->match('/users', 'POST');
        $this->assertNotNull($matcher);
        $this->assertSame('UserController::create', $matcher->getHandler());
    }

    /**
     * Test route matching with explicit URI and method parameters.
     *
     * Verifies that match() accepts explicit URI and method parameters
     * instead of using request values.
     */
    public function testMatchWithExplicitParameters(): void
    {
        // Arrange
        $this->router->addGet('/users', 'UserController::index');

        // Act
        $matcher = $this->router->match('/users', 'GET');

        // Assert
        $this->assertNotNull($matcher, 'Router should match with explicit URI and method');
        $this->assertSame('UserController::index', $matcher->getHandler());
    }

    /**
     * Wildcard route should also match less-common verbs like PATCH.
     */
    public function testAddAnyRouteMatchesPatchMethod(): void
    {
        $this->router->addAny('/status', 'HealthController::patchAware');

        $matcher = $this->router->match('/status', 'PATCH');

        $this->assertNotNull($matcher);
        $this->assertSame('HealthController::patchAware', $matcher->getHandler());
    }
}
