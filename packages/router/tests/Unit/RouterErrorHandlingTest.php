<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Unit;

use Switon\Routing\Tests\TestCase;

/**
 * Test cases for Router error handling and edge cases.
 *
 * Tests error conditions, boundary cases, and robustness scenarios
 * from a user's perspective using public API.
 */
class RouterErrorHandlingTest extends TestCase
{
    /**
     * Empty URI does not match; router raises {@see \Switon\Routing\Exception\NotFoundRouteException}.
     */
    public function testMatchEmptyUriThrowsNotFoundRouteException(): void
    {
        // Arrange
        $this->router->addGet('/users', 'UserController::index');

        // Act & Assert
        $this->expectException(\Switon\Routing\Exception\NotFoundRouteException::class);
        $this->router->match('', 'GET');
    }

    /**
     * Test match handles special characters in URI.
     *
     * Verifies that match() handles URIs with special characters correctly.
     */
    public function testMatchHandlesSpecialCharacters(): void
    {
        // Arrange
        $this->router->addGet('/users/123', 'UserController::show');

        // Act
        $matcher = $this->router->match('/users/123', 'GET');

        // Assert
        $this->assertNotNull($matcher, 'Router should match URI with numeric ID');
    }

    /**
     * Test match handles very long URIs.
     *
     * Verifies that match() handles long URIs without issues.
     */
    public function testMatchHandlesLongUri(): void
    {
        // Arrange
        $longPath = '/users/' . str_repeat('a', 1000);
        $this->router->addGet($longPath, 'UserController::show');

        // Act
        $matcher = $this->router->match($longPath, 'GET');

        // Assert
        $this->assertNotNull($matcher, 'Router should handle long URIs');
        $this->assertSame('UserController::show', $matcher->getHandler());
    }

    /**
     * Test match handles case-sensitive path matching.
     *
     * Verifies that match() respects case sensitivity for literal routes.
     */
    public function testMatchIsCaseSensitive(): void
    {
        // Arrange
        $this->router->addGet('/Users', 'UserController::index');

        // Act & Assert
        $this->expectException(\Switon\Routing\Exception\NotFoundRouteException::class);
        $this->router->match('/users', 'GET');
    }

    /**
     * Test match handles multiple routes with same pattern different methods.
     *
     * Verifies that match() correctly distinguishes routes by HTTP method.
     */
    public function testMatchHandlesMultipleRoutesSamePatternDifferentMethods(): void
    {
        // Arrange
        $this->router->addGet('/users/{id}', 'UserController::show');
        $this->router->addPut('/users/{id}', 'UserController::update');
        $this->router->addDelete('/users/{id}', 'UserController::delete');

        // Test GET
        $matcher = $this->router->match('/users/123', 'GET');
        $this->assertNotNull($matcher);
        $this->assertSame('UserController::show', $matcher->getHandler());

        // Test PUT
        $matcher = $this->router->match('/users/123', 'PUT');
        $this->assertNotNull($matcher);
        $this->assertSame('UserController::update', $matcher->getHandler());

        // Test DELETE
        $matcher = $this->router->match('/users/123', 'DELETE');
        $this->assertNotNull($matcher);
        $this->assertSame('UserController::delete', $matcher->getHandler());
    }

    /**
     * Test prefix with empty string.
     *
     * Verifies that setPrefix('') correctly disables prefix.
     */
    public function testPrefixWithEmptyString(): void
    {
        // Arrange
        $this->router->setPrefix('/api');
        $this->router->setPrefix('');
        $this->router->addGet('/users', 'UserController::index');

        // Act
        $prefix = $this->router->getPrefix();
        $matcher = $this->router->match('/users', 'GET');

        // Assert
        $this->assertSame('', $prefix, 'getPrefix() should return empty string when prefix is empty');
        $this->assertNotNull($matcher, 'Router should match routes when prefix is empty');
    }

    public function testMatchThrowsWhenUriDoesNotMatchConditionalPrefix(): void
    {
        $this->router->setPrefix('?/v\d+');
        $this->router->addGet('/users', 'UserController::index');

        $this->expectException(\Switon\Routing\Exception\NotFoundRouteException::class);
        $this->expectExceptionMessage('dynamic prefix pattern');

        $this->router->match('/wrong/users', 'GET');
    }

}
