<?php

declare(strict_types=1);

namespace Switon\Routing;

use Switon\Routing\Exception\NotFoundRouteException;

/**
 * Defines route registration and request matching for the router component.
 *
 * Use when registering literal or dynamic routes and resolving handlers from URI plus HTTP verb.
 *
 * Road-signs:
 * - static prefix vs ?dynamic prefix
 * - reverse-proxy / tenant-mapped first segment
 * - getPrefix() resolves or returns configured prefix
 * - match() strips prefix before route lookup
 * - prefix mismatch throws NotFoundRouteException
 * - RouteParamsResolved / RouteNotFound
 *
 * @see \Switon\Routing\Router
 * @see \Switon\Routing\RouterInterface::match()
 * @see \Switon\Routing\RouteInterface
 * @see \Switon\Routing\MatcherInterface
 * @see \Switon\Routing\Exception
 * @see \Switon\Http\RequestHandler Typical consumer
 * @see \Switon\Inspector\Collector\DependencyCollector Typical consumer
 * @see \Switon\Routing\RouteRegistrarInterface Attribute-based route registration
 * @see \Switon\Routing\Event\RouteParamsResolved
 * @see \Switon\Routing\Event\RouteNotFound
 */
interface RouterInterface
{
    /**
     * Sets the route prefix used during registration and matching.
     */
    public function setPrefix(string $prefix): static;

    /**
     * Returns the effective route prefix.
     *
     * Guidance: Use `?` prefixes for reverse-proxy or tenant-mapped first segments and return the full matched prefix.
     *
     * @param string|null $uri When prefix is dynamic (starts with <code>?</code>), pass URI to resolve the full matched prefix from the path; omit to return configured prefix as-is. If URI is provided but does not match the dynamic pattern, throws.
     *
     * @throws NotFoundRouteException When prefix is dynamic and URI does not match the pattern.
     *
     * @see \Switon\Routing\Router::getPrefix() Default implementation
     * @see \Switon\Routing\Router::match() Prefix is applied by stripping from URI before matching
     */
    public function getPrefix(?string $uri = null): string;

    /**
     * Registers one route.
     *
     * @param string $method HTTP verb or <code>*</code>.
     * @param string $pattern Route pattern.
     * @param string|array<string> $handler Controller handler string or <code>[class, method]</code> pair.
     */
    public function add(string $method, string $pattern, string|array $handler): void;

    /**
     * Registers a wildcard route.
     *
     * @param string|array<string> $handler
     */
    public function addAny(string $pattern, string|array $handler): void;

    /**
     * Registers a GET route.
     *
     * @param string|array<string> $handler
     */
    public function addGet(string $pattern, string|array $handler): void;

    /**
     * Registers a POST route.
     *
     * @param string|array<string> $handler
     */
    public function addPost(string $pattern, string|array $handler): void;

    /**
     * Registers a PUT route.
     *
     * @param string|array<string> $handler
     */
    public function addPut(string $pattern, string|array $handler): void;

    /**
     * Registers a PATCH route.
     *
     * @param string|array<string> $handler
     */
    public function addPatch(string $pattern, string|array $handler): void;

    /**
     * Registers a DELETE route.
     *
     * @param string|array<string> $handler
     */
    public function addDelete(string $pattern, string|array $handler): void;

    /**
     * Registers a HEAD route.
     *
     * @param string|array<string> $handler
     */
    public function addHead(string $pattern, string|array $handler): void;

    /**
     * Registers an OPTIONS route.
     *
     * @param string|array<string> $handler
     */
    public function addOptions(string $pattern, string|array $handler): void;

    /**
     * Matches URI and HTTP verb, then returns resolved handler plus variables.
     *
     * @throws NotFoundRouteException
     *
     * @see \Switon\Routing\Router::match() Default implementation
     * @see \Switon\Routing\Event\RouteNotFound Event on route miss
     * @see \Switon\Routing\Event\RouteParamsResolved Event on match success
     */
    public function match(string $uri, string $verb): MatcherInterface;

    /**
     * Returns all registered routes.
     *
     * @return array<int, array{verb: string, pattern: string, handler: string}>
     *
     * @see \Switon\Routing\RouteRegistrarInterface Route manifest and cache producer
     */
    public function getAllRoutes(): array;
}
