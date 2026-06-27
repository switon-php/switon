<?php

declare(strict_types=1);

namespace Switon\Routing\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Routing\RouterInterface;

/**
 * Event emitted when route matching fails.
 *
 * Log category: router matching.
 *
 * @see \Switon\Routing\RouterInterface
 * @see \Switon\Routing\RouterInterface::match()
 */
#[EventLevel(Severity::INFO)]
class RouteNotFound implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param string $uri Request URI.
     * @param string $method Request HTTP method.
     */
    public function __construct(
        public RouterInterface $sender,
        public string          $uri,
        public string          $method
    ) {
    }

    public function getSender(): RouterInterface
    {
        return $this->sender;
    }

    /**
     * Returns a compact payload describing the failed route lookup.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'uri' => $this->uri,
            'method' => $this->method,
        ];
    }
}
