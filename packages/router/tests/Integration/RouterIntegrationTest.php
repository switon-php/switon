<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Integration;

use Switon\Routing\Tests\TestCase;

/**
 * Integration tests for complete routing workflows.
 *
 * Tests end-to-end scenarios including route registration,
 * matching, parameter extraction, and error handling.
 */
class RouterIntegrationTest extends TestCase
{
    /**
     * Test complete workflow: register routes, match URI, extract parameters.
     */
    public function testCompleteRoutingWorkflow(): void
    {
        // Arrange: Register multiple routes
        $this->router->addGet('/users', 'UserController::index');
        $this->router->addGet('/users/{id}', 'UserController::show');
        $this->router->addPost('/users', 'UserController::create');
        $this->router->addPut('/users/{id}', 'UserController::update');
        $this->router->addDelete('/users/{id}', 'UserController::delete');

        // Act & Assert: Test each route
        $matcher = $this->router->match('/users', 'GET');
        $this->assertEquals('UserController::index', $matcher->getHandler());
        $this->assertEmpty($matcher->getVariables());

        $matcher = $this->router->match('/users/123', 'GET');
        $this->assertEquals('UserController::show', $matcher->getHandler());
        $this->assertEquals(['id' => '123'], $matcher->getVariables());

        $matcher = $this->router->match('/users', 'POST');
        $this->assertEquals('UserController::create', $matcher->getHandler());

        $matcher = $this->router->match('/users/456', 'PUT');
        $this->assertEquals('UserController::update', $matcher->getHandler());
        $this->assertEquals(['id' => '456'], $matcher->getVariables());

        $matcher = $this->router->match('/users/789', 'DELETE');
        $this->assertEquals('UserController::delete', $matcher->getHandler());
        $this->assertEquals(['id' => '789'], $matcher->getVariables());
    }

    /**
     * Test routing with prefix configuration.
     */
    public function testPrefixedRoutingWorkflow(): void
    {
        // Arrange: Set prefix and register routes
        $this->router->setPrefix('/api/v1');
        $this->router->addGet('/users', 'ApiUserController::index');
        $this->router->addGet('/users/{id}', 'ApiUserController::show');

        // Act & Assert: Match with prefix
        $matcher = $this->router->match('/api/v1/users', 'GET');
        $this->assertEquals('ApiUserController::index', $matcher->getHandler());

        $matcher = $this->router->match('/api/v1/users/123', 'GET');
        $this->assertEquals('ApiUserController::show', $matcher->getHandler());
        $this->assertEquals(['id' => '123'], $matcher->getVariables());
    }

    /**
     * Test complex routing scenario with mixed route types.
     */
    public function testMixedRouteTypesWorkflow(): void
    {
        // Arrange: Register various route types
        $this->router->addGet('/', 'HomeController::index');
        $this->router->addGet('/about', 'PageController::about');
        $this->router->addGet('/posts/{slug}', 'PostController::show');
        $this->router->addGet('/users/{id}/posts/{postId}', 'UserPostController::show');
        $this->router->addGet('/api/articles', 'ArticleController::indexAction');
        $this->router->addAny('/health', 'HealthController::check');

        // Act & Assert: Test each route type
        $matcher = $this->router->match('/', 'GET');
        $this->assertEquals('HomeController::index', $matcher->getHandler());

        $matcher = $this->router->match('/about', 'GET');
        $this->assertEquals('PageController::about', $matcher->getHandler());

        $matcher = $this->router->match('/posts/my-first-post', 'GET');
        $this->assertEquals('PostController::show', $matcher->getHandler());
        $this->assertEquals(['slug' => 'my-first-post'], $matcher->getVariables());

        $matcher = $this->router->match('/users/10/posts/20', 'GET');
        $this->assertEquals('UserPostController::show', $matcher->getHandler());
        $this->assertEquals(['id' => '10', 'postId' => '20'], $matcher->getVariables());

        $matcher = $this->router->match('/api/articles', 'GET');
        $this->assertEquals('ArticleController::indexAction', $matcher->getHandler());
        $this->assertEmpty($matcher->getVariables());

        $matcher = $this->router->match('/health', 'POST');
        $this->assertEquals('HealthController::check', $matcher->getHandler());
    }

    /**
     * Test route priority and override behavior.
     */
    public function testRoutePriorityWorkflow(): void
    {
        // Arrange: Register routes - literal route first
        $this->router->addGet('/users/new', 'UserController::new');
        $this->router->addGet('/users/{id}', 'UserController::show');

        // Act & Assert: Literal route should match
        $matcher = $this->router->match('/users/new', 'GET');
        $this->assertEquals('UserController::new', $matcher->getHandler());

        // Dynamic route matches numeric ID
        $matcher = $this->router->match('/users/123', 'GET');
        $this->assertEquals('UserController::show', $matcher->getHandler());
        $this->assertEquals(['id' => '123'], $matcher->getVariables());
    }

    /**
     * Test error handling in complete workflow.
     */
    public function testErrorHandlingWorkflow(): void
    {
        // Arrange: Register some routes
        $this->router->addGet('/users', 'UserController::index');
        $this->router->addGet('/users/{id}', 'UserController::show');

        // Act & Assert: Test 404 error
        $this->expectException(\Switon\Routing\Exception\NotFoundRouteException::class);
        $this->router->match('/posts', 'GET');
    }

    /**
     * Test parameter extraction with various types.
     */
    public function testParameterExtractionWorkflow(): void
    {
        // Arrange: Register routes with different parameter types
        $this->router->addGet('/users/{id}', 'UserController::show');
        $this->router->addGet('/posts/{slug}', 'PostController::show');
        $this->router->addGet('/categories/{name}/posts/{postId}', 'CategoryPostController::show');

        // Act & Assert: Test parameter extraction
        $matcher = $this->router->match('/users/123', 'GET');
        $this->assertEquals(['id' => '123'], $matcher->getVariables());

        // Default type allows anything except slashes
        $matcher = $this->router->match('/posts/my-article', 'GET');
        $this->assertEquals(['slug' => 'my-article'], $matcher->getVariables());

        // Multiple parameters
        $matcher = $this->router->match('/categories/tech/posts/42', 'GET');
        $this->assertEquals(['name' => 'tech', 'postId' => '42'], $matcher->getVariables());
    }

    /**
     * Test getAllRoutes returns all registered routes.
     */
    public function testGetAllRoutesWorkflow(): void
    {
        // Arrange: Register various routes
        $this->router->addGet('/users', 'UserController::index');
        $this->router->addPost('/users', 'UserController::create');
        $this->router->addGet('/users/{id}', 'UserController::show');
        $this->router->addGet('/posts', 'PostController::index');

        // Act: Get all routes
        $routes = $this->router->getAllRoutes();

        // Assert: Check route count and structure
        $this->assertIsArray($routes);
        $this->assertGreaterThanOrEqual(4, count($routes));

        // Check that routes have expected structure
        foreach ($routes as $route) {
            $this->assertArrayHasKey('verb', $route);
            $this->assertArrayHasKey('pattern', $route);
            $this->assertArrayHasKey('handler', $route);
        }
    }
}
