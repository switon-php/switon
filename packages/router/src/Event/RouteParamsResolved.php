<?php

declare(strict_types=1);

namespace Switon\Routing\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Routing\MatcherInterface;
use Switon\Routing\RouterInterface;

/**
 * Event emitted after a route handler and variables are resolved.
 *
 * Log category: `switon.routing.route.params.resolved`
 *
 * @see \Switon\Routing\RouterInterface
 * @see \Switon\Routing\RouterInterface::match()
 * @see \Switon\Routing\MatcherInterface
 */
#[EventLevel(Severity::DEBUG)]
class RouteParamsResolved implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param string $uri Matched request URI.
     * @param string $verb Matched HTTP method.
     * @param MatcherInterface $matcher Resolved route match result.
     */
    public function __construct(
        public RouterInterface $sender,
        public string           $uri,
        public string           $verb,
        public MatcherInterface $matcher
    ) {
    }

    public function getSender(): RouterInterface
    {
        return $this->sender;
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
