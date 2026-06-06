<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Unit;

use Switon\Routing\Tests\TestCase;

/**
 * Test cases for Router::getAllRoutes() functionality.
 *
 * Tests route listing with various route types: literal, dynamic, REST, mixed.
 */
class RouterGetAllRoutesTest extends TestCase
{
    /**
     * Test getAllRoutes returns empty array when no routes registered.
     */
    public function testGetAllRoutesReturnsEmptyArrayWhenNoRoutes(): void
    {
        // Act
        $routes = $this->router->getAllRoutes();

        // Assert
        $this->assertSame([], $routes, 'getAllRoutes should return empty array when no routes registered');
    }

    /**
     * Test getAllRoutes returns literal routes with correct structure.
     */
    public function testGetAllRoutesReturnsLiteralRoutes(): void
    {
        // Arrange
        $this->router->addGet('/users', 'UserController::index');
        $this->router->addPost('/users', 'UserController::create');

        // Act
        $routes = $this->router->getAllRoutes();

        // Assert
        $this->assertCount(2, $routes, 'Should return 2 literal routes');
        foreach ($routes as $route) {
            $this->assertArrayHasKey('verb', $route, 'Each route should have verb key');
            $this->assertArrayHasKey('pattern', $route, 'Each route should have pattern key');
            $this->assertArrayHasKey('handler', $route, 'Each route should have handler key');
        }
    }

    /**
     * Test getAllRoutes returns dynamic routes with correct structure.
     */
    public function testGetAllRoutesReturnsDynamicRoutes(): void
    {
        // Arrange
        $this->router->addGet('/users/{id}', 'UserController::show');

        // Act
        $routes = $this->router->getAllRoutes();

        // Assert
        $this->assertCount(1, $routes);
        $this->assertSame('GET', $routes[0]['verb']);
        $this->assertSame('/users/{id}', $routes[0]['pattern']);
        $this->assertSame('UserController::show', $routes[0]['handler']);
    }

    /**
     * Test getAllRoutes with mixed route types returns all routes.
     *
     * Verifies that literal and dynamic routes are all included.
     */
    public function testGetAllRoutesWithMixedRouteTypes(): void
    {
        // Arrange
        $this->router->addGet('/home', 'HomeController::index');           // literal
        $this->router->addGet('/users/{id}', 'UserController::show');      // dynamic
        $this->router->addPost('/users', 'UserController::create');        // literal
        $this->router->addGet('/articles', 'ArticleController::indexAction'); // literal

        // Act
        $routes = $this->router->getAllRoutes();

        // Assert
        $this->assertCount(4, $routes, 'Should return all 4 routes (3 literal + 1 dynamic)');

        // Verify patterns exist
        $patterns = array_column($routes, 'pattern');
        $this->assertContains('/home', $patterns);
        $this->assertContains('/users/{id}', $patterns);
        $this->assertContains('/users', $patterns);
        $this->assertContains('/articles', $patterns);
    }

    /**
     * Test getAllRoutes includes wildcard method routes.
     */
    public function testGetAllRoutesIncludesWildcardRoutes(): void
    {
        // Arrange
        $this->router->addAny('/health', 'HealthController::check');

        // Act
        $routes = $this->router->getAllRoutes();

        // Assert
        $this->assertCount(1, $routes);
        $this->assertSame('*', $routes[0]['verb']);
        $this->assertSame('/health', $routes[0]['pattern']);
        $this->assertSame('HealthController::check', $routes[0]['handler']);
    }
}
