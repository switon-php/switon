<?php

declare(strict_types=1);

namespace Switon\Routing\Event;

use JsonSerializable;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Routing\MatcherInterface;

/**
 * Event emitted after a route handler and variables are resolved.
 *
 * Log category: router matching.
 *
 * @see \Switon\Routing\RouterInterface
 * @see \Switon\Routing\RouterInterface::match()
 * @see \Switon\Routing\MatcherInterface
 */
#[EventLevel(Severity::DEBUG)]
class RouteParamsResolved implements JsonSerializable
{
    /**
     * @param string $uri Matched request URI.
     * @param string $verb Matched HTTP method.
     * @param MatcherInterface $matcher Resolved route match result.
     */
    public function __construct(
        public string           $uri,
        public string           $verb,
        public MatcherInterface $matcher
    ) {
    }

    /**
     * Returns the resolved handler and extracted route parameters.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'handler' => $this->matcher->getHandler(),
            'params' => $this->matcher->getVariables(),
        ];
    }
}
