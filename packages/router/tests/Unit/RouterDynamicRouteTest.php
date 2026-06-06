<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Unit;

use Switon\Routing\MatcherInterface;
use Switon\Routing\Tests\TestCase;

/**
 * Test cases for Router dynamic route functionality.
 *
 * Tests dynamic routes with parameters: parameter extraction, multiple parameters.
 */
class RouterDynamicRouteTest extends TestCase
{
    /**
     * Test dynamic route with single parameter.
     *
     * Verifies that routes with parameters can be registered and matched,
     * and parameters are extracted correctly.
     */
    public function testDynamicRouteWithSingleParameter(): void
    {
        // Arrange
        $this->router->addGet('/users/{id}', 'UserController::show');

        // Act
        $matcher = $this->router->match('/users/123', 'GET');

        // Assert
        $this->assertNotNull($matcher, 'Router should match dynamic route');
        $this->assertInstanceOf(MatcherInterface::class, $matcher);
        $this->assertSame('UserController::show', $matcher->getHandler());
        $this->assertArrayHasKey('id', $matcher->getVariables());
        $this->assertSame('123', $matcher->getVariables()['id']);
    }

    /**
     * Test dynamic route with multiple parameters.
     *
     * Verifies that routes with multiple parameters extract all parameters correctly.
     */
    public function testDynamicRouteWithMultipleParameters(): void
    {
        // Arrange
        $this->router->addGet('/users/{userId}/posts/{postId}', 'PostController::show');

        // Act
        $matcher = $this->router->match('/users/123/posts/456', 'GET');

        // Assert
        $this->assertNotNull($matcher, 'Router should match route with multiple parameters');
        $variables = $matcher->getVariables();
        $this->assertArrayHasKey('userId', $variables);
        $this->assertArrayHasKey('postId', $variables);
        $this->assertSame('123', $variables['userId']);
        $this->assertSame('456', $variables['postId']);
    }

    /**
     * Test dynamic route matching respects order (last registered first).
     *
     * Verifies that when multiple dynamic routes could match, the last registered one wins.
     */
    public function testDynamicRouteMatchingOrder(): void
    {
        // Arrange
        $this->router->addGet('/users/{id}', 'UserController::show');
        $this->router->addGet('/users/{slug}', 'UserController::showBySlug');

        // Act
        $matcher = $this->router->match('/users/123', 'GET');

        // Assert
        $this->assertNotNull($matcher, 'Router should match one of the dynamic routes');
        // Last registered route should win
        $this->assertSame('UserController::showBySlug', $matcher->getHandler());
    }

    /**
     * Non-matching URI raises {@see \Switon\Routing\Exception\NotFoundRouteException}.
     */
    public function testDynamicRouteNonMatchingUriThrowsNotFoundRouteException(): void
    {
        // Arrange
        $this->router->addGet('/users/{id}', 'UserController::show');

        // Act & Assert
        $this->expectException(\Switon\Routing\Exception\NotFoundRouteException::class);
        $this->router->match('/posts/123', 'GET');
    }

    /**
     * Test dynamic route with POST method.
     *
     * Verifies that dynamic routes work with different HTTP methods.
     */
    public function testDynamicRouteWithPostMethod(): void
    {
        // Arrange
        $this->router->addPost('/users/{id}', 'UserController::update');

        // Act
        $matcher = $this->router->match('/users/123', 'POST');

        // Assert
        $this->assertNotNull($matcher, 'Router should match dynamic POST route');
        $this->assertSame('UserController::update', $matcher->getHandler());
        $this->assertSame('123', $matcher->getVariables()['id']);
    }
}
