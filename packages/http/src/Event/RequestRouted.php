<?php

declare(strict_types=1);

namespace Switon\Http\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Http\RequestInterface;
use Switon\Http\RequestHandlerInterface;
use Switon\Routing\MatcherInterface;
use Switon\Routing\RouterInterface;

/**
 * Event emitted after route resolution completes.
 *
 * Log category: <code>switon.http.request.routed</code>
 *
 * @see \Switon\Http\RequestHandler::handle()
 * @see \Switon\Http\Event\RequestRouting
 * @see \Switon\Routing\MatcherInterface
 */
#[EventLevel(Severity::DEBUG)]
class RequestRouted implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public RequestHandlerInterface $sender,
        public RouterInterface   $router,
        public ?MatcherInterface $matcher,
        public RequestInterface  $request
    ) {

    }

    public function getSender(): RequestHandlerInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $uri = $this->request->path();

        return [
            'path' => $uri,
            'handler' => $this->matcher?->getHandler(),
            'variables' => $this->matcher?->getVariables(),
        ];
    }
}
