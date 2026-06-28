<?php

declare(strict_types=1);

namespace Switon\Http\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Http\RequestInterface;
use Switon\Http\RequestHandlerInterface;
use Switon\Routing\RouterInterface;

/**
 * Event emitted before route resolution starts.
 *
 * Log category: <code>switon.http.request.routing</code>
 *
 * @see \Switon\Http\RequestHandler::handle()
 * @see \Switon\Http\Event\RequestRouted
 * @see \Switon\Routing\RouterInterface
 */
#[EventLevel(Severity::DEBUG)]
class RequestRouting implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public RequestHandlerInterface $sender,
        public RouterInterface  $router,
        public RequestInterface $request
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
        ];
    }
}
