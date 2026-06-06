<?php

declare(strict_types=1);

namespace Switon\Routing;

/**
 * Defines how one route object matches URI and HTTP verb.
 *
 * Use when implementing dynamic route behaviors such as regex routes.
 *
 * @see \Switon\Routing\RouterInterface::add()
 * @see \Switon\Routing\RouterInterface::match()
 * @see \Switon\Routing\RegexRoute
 * @see \Switon\Routing\MatcherInterface
 */
interface RouteInterface
{
    /**
     * Attempts to match URI and verb.
     *
     * @return MatcherInterface|null Null when URI or verb does not match.
     */
    public function match(string $uri, string $verb = 'GET'): ?MatcherInterface;
}
