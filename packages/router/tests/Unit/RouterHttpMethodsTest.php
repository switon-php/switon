<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Unit;

use Switon\Routing\MatcherInterface;
use Switon\Routing\Tests\TestCase;

/**
 * Test cases for Router HTTP method registration methods.
 *
 * Tests all HTTP method shortcuts: addPut(), addPatch(), addDelete(), addHead(), addOptions().
 * These tests verify that each public method works correctly from a user's perspective.
 */
class RouterHttpMethodsTest extends TestCase
{
    /**
     * Test addPut registers PUT route correctly.
     *
     * Verifies that addPut() registers a route for PUT method
     * and it can be matched correctly.
     */
    public function testAddPutRoute(): void
    {
        // Arrange
        $this->router->addPut('/users/{id}', 'UserController::update');

        // Act
        $matcher = $this->router->match('/users/123', 'PUT');

        // Assert
        $this->assertNotNull($matcher, 'Router should match PUT route');
        $this->assertInstanceOf(MatcherInterface::class, $matcher);
        $this->assertSame('UserController::update', $matcher->getHandler());
        $this->assertArrayHasKey('id', $matcher->getVariables());
        $this->assertSame('123', $matcher->getVariables()['id']);
    }

    /**
     * Test addPatch registers PATCH route correctly.
     *
     * Verifies that addPatch() registers a route for PATCH method
     * and it can be matched correctly.
     */
    public function testAddPatchRoute(): void
    {
        // Arrange
        $this->router->addPatch('/users/{id}', 'UserController::patch');

        // Act
        $matcher = $this->router->match('/users/123', 'PATCH');

        // Assert
        $this->assertNotNull($matcher, 'Router should match PATCH route');
        $this->assertSame('UserController::patch', $matcher->getHandler());
        $this->assertSame('123', $matcher->getVariables()['id']);
    }

    /**
     * Test addDelete registers DELETE route correctly.
     *
     * Verifies that addDelete() registers a route for DELETE method
     * and it can be matched correctly.
     */
    public function testAddDeleteRoute(): void
    {
        // Arrange
        $this->router->addDelete('/users/{id}', 'UserController::delete');

        // Act
        $matcher = $this->router->match('/users/123', 'DELETE');

        // Assert
        $this->assertNotNull($matcher, 'Router should match DELETE route');
        $this->assertSame('UserController::delete', $matcher->getHandler());
        $this->assertSame('123', $matcher->getVariables()['id']);
    }

    /**
     * Test addHead registers HEAD route correctly.
     *
     * Verifies that addHead() registers a route for HEAD method
     * and it can be matched correctly.
     */
    public function testAddHeadRoute(): void
    {
        // Arrange
        $this->router->addHead('/users/{id}', 'UserController::head');

        // Act
        $matcher = $this->router->match('/users/123', 'HEAD');

        // Assert
        $this->assertNotNull($matcher, 'Router should match HEAD route');
        $this->assertSame('UserController::head', $matcher->getHandler());
        $this->assertSame('123', $matcher->getVariables()['id']);
    }

    /**
     * Test addOptions registers OPTIONS route correctly.
     *
     * Verifies that addOptions() registers a route for OPTIONS method
     * and it can be matched correctly.
     */
    public function testAddOptionsRoute(): void
    {
        // Arrange
        $this->router->addOptions('/users', 'UserController::options');

        // Act
        $matcher = $this->router->match('/users', 'OPTIONS');

        // Assert
        $this->assertNotNull($matcher, 'Router should match OPTIONS route');
        $this->assertSame('UserController::options', $matcher->getHandler());
    }

    /**
     * Test HTTP methods are properly distinguished.
     *
     * Verifies that routes registered with different HTTP methods
     * are correctly distinguished during matching.
     */
    public function testHttpMethodsAreProperlyDistinguished(): void
    {
        // Arrange - register same path with different methods
        $this->router->addGet('/users/{id}', 'UserController::show');
        $this->router->addPut('/users/{id}', 'UserController::update');
        $this->router->addPatch('/users/{id}', 'UserController::patch');
        $this->router->addDelete('/users/{id}', 'UserController::delete');
        $this->router->addHead('/users/{id}', 'UserController::head');

        // Test PUT
        $matcher = $this->router->match('/users/123', 'PUT');
        $this->assertNotNull($matcher);
        $this->assertSame('UserController::update', $matcher->getHandler());

        // Test PATCH
        $matcher = $this->router->match('/users/123', 'PATCH');
        $this->assertNotNull($matcher);
        $this->assertSame('UserController::patch', $matcher->getHandler());

        // Test DELETE
        $matcher = $this->router->match('/users/123', 'DELETE');
        $this->assertNotNull($matcher);
        $this->assertSame('UserController::delete', $matcher->getHandler());

        // Test HEAD
        $matcher = $this->router->match('/users/123', 'HEAD');
        $this->assertNotNull($matcher);
        $this->assertSame('UserController::head', $matcher->getHandler());
    }

    /**
     * Test HTTP methods with array handler format.
     *
     * Verifies that HTTP method shortcuts accept array handler format
     * and convert it to string format correctly.
     */
    public function testHttpMethodsWithArrayHandler(): void
    {
        // Arrange
        $this->router->addPut('/users/{id}', ['UserController', 'update']);
        $this->router->addPatch('/users/{id}', ['UserController', 'patch']);
        $this->router->addDelete('/users/{id}', ['UserController', 'delete']);

        // Test PUT
        $matcher = $this->router->match('/users/123', 'PUT');
        $this->assertNotNull($matcher);
        $this->assertSame('UserController::update', $matcher->getHandler());

        // Test PATCH
        $matcher = $this->router->match('/users/123', 'PATCH');
        $this->assertNotNull($matcher);
        $this->assertSame('UserController::patch', $matcher->getHandler());

        // Test DELETE
        $matcher = $this->router->match('/users/123', 'DELETE');
        $this->assertNotNull($matcher);
        $this->assertSame('UserController::delete', $matcher->getHandler());
    }
}
