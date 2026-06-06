<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Unit;

use Switon\Routing\Event\RouteNotFound;
use Switon\Routing\Event\RouteParamsResolved;
use Switon\Routing\Exception\NotFoundRouteException;
use Switon\Routing\Tests\TestCase;

/**
 * Test cases for Router event dispatching behavior.
 *
 * Tests that the Router dispatches appropriate events during matching:
 * RouteParamsResolved on success, RouteNotFound on failure.
 */
class RouterEventTest extends TestCase
{
    /**
     * Test RouteParamsResolved event dispatched on successful match.
     *
     * Verifies that a RouteParamsResolved event is dispatched with correct
     * URI, verb, and matcher after a successful route match.
     */
    public function testRouteParamsResolvedDispatchedOnSuccessfulMatch(): void
    {
        // Arrange
        $this->router->addGet('/users', 'UserController::index');
        $this->eventDispatcher->clearDispatchedEvents();

        // Act
        $this->router->match('/users', 'GET');

        // Assert
        $events = $this->eventDispatcher->getDispatchedEvents();
        $this->assertCount(1, $events, 'Exactly one event should be dispatched on successful match');
        $this->assertInstanceOf(RouteParamsResolved::class, $events[0], 'Event should be RouteParamsResolved');
        $this->assertSame('/users', $events[0]->uri);
        $this->assertSame('GET', $events[0]->verb);
        $this->assertSame('UserController::index', $events[0]->matcher->getHandler());
    }

    /**
     * Test RouteParamsResolved event contains extracted parameters.
     *
     * Verifies that the matcher in the event contains the correct
     * extracted route parameters.
     */
    public function testRouteParamsResolvedContainsParameters(): void
    {
        // Arrange
        $this->router->addGet('/users/{id}', 'UserController::show');
        $this->eventDispatcher->clearDispatchedEvents();

        // Act
        $this->router->match('/users/42', 'GET');

        // Assert
        $events = $this->eventDispatcher->getDispatchedEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(RouteParamsResolved::class, $events[0]);
        $this->assertSame(['id' => '42'], $events[0]->matcher->getVariables());
    }

    /**
     * Test RouteNotFound event dispatched when no route matches.
     *
     * Verifies that a RouteNotFound event is dispatched before the
     * NotFoundRouteException is thrown.
     */
    public function testRouteNotFoundDispatchedOnNoMatch(): void
    {
        // Arrange
        $this->router->addGet('/users', 'UserController::index');
        $this->eventDispatcher->clearDispatchedEvents();

        // Act
        try {
            $this->router->match('/nonexistent', 'GET');
        } catch (NotFoundRouteException) {
            // Expected
        }

        // Assert
        $events = $this->eventDispatcher->getDispatchedEvents();
        $this->assertNotEmpty($events, 'At least one event should be dispatched on route not found');

        $routeNotFoundEvents = array_filter($events, fn ($e) => $e instanceof RouteNotFound);
        $this->assertCount(1, $routeNotFoundEvents, 'Exactly one RouteNotFound event should be dispatched');

        $event = array_first($routeNotFoundEvents);
        $this->assertSame('/nonexistent', $event->uri);
        $this->assertSame('GET', $event->method);
    }

    /**
     * Test RouteNotFound event dispatched when prefix does not match.
     *
     * Verifies that a RouteNotFound event is dispatched when the URI
     * does not match the router prefix.
     */
    public function testRouteNotFoundDispatchedOnPrefixMismatch(): void
    {
        // Arrange
        $this->router->setPrefix('/api');
        $this->router->addGet('/users', 'UserController::index');
        $this->eventDispatcher->clearDispatchedEvents();

        // Act
        try {
            $this->router->match('/web/users', 'GET');
        } catch (NotFoundRouteException) {
            // Expected
        }

        // Assert
        $events = $this->eventDispatcher->getDispatchedEvents();
        $routeNotFoundEvents = array_filter($events, fn ($e) => $e instanceof RouteNotFound);
        $this->assertCount(1, $routeNotFoundEvents, 'RouteNotFound event should be dispatched on prefix mismatch');
    }

    /**
     * Test RouteNotFound event jsonSerialize returns correct structure.
     *
     * Verifies the event serialization format for logging.
     */
    public function testRouteNotFoundEventSerializesCorrectly(): void
    {
        // Arrange
        $this->router->addGet('/users', 'UserController::index');
        $this->eventDispatcher->clearDispatchedEvents();

        // Act
        try {
            $this->router->match('/missing', 'POST');
        } catch (NotFoundRouteException) {
            // Expected
        }

        // Assert
        $events = array_filter($this->eventDispatcher->getDispatchedEvents(), fn ($e) => $e instanceof RouteNotFound);
        $event = array_first($events);
        $serialized = $event->jsonSerialize();
        $this->assertSame('/missing', $serialized['uri']);
        $this->assertSame('POST', $serialized['method']);
    }

    /**
     * Test RouteParamsResolved event jsonSerialize returns correct structure.
     *
     * Verifies the event serialization format for logging.
     */
    public function testRouteParamsResolvedEventSerializesCorrectly(): void
    {
        // Arrange
        $this->router->addGet('/users/{id}', 'UserController::show');
        $this->eventDispatcher->clearDispatchedEvents();

        // Act
        $this->router->match('/users/99', 'GET');

        // Assert
        $events = $this->eventDispatcher->getDispatchedEvents();
        $serialized = $events[0]->jsonSerialize();
        $this->assertSame('UserController::show', $serialized['handler']);
        $this->assertSame(['id' => '99'], $serialized['params']);
    }
}
